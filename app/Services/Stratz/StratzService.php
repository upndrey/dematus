<?php

namespace App\Services\Stratz;

class StratzService
{
    /**
     * @var array<string, int>
     */
    private const GAME_MODE_IDS = [
        'NONE' => 0,
        'ALL_PICK' => 1,
        'CAPTAINS_MODE' => 2,
        'RANDOM_DRAFT' => 3,
        'SINGLE_DRAFT' => 4,
        'ALL_RANDOM' => 5,
        'INTRO' => 6,
        'DIRETIDE' => 7,
        'REVERSE_CAPTAINS_MODE' => 8,
        'THE_GREEVILING' => 9,
        'TUTORIAL' => 10,
        'MID_ONLY' => 11,
        'LEAST_PLAYED' => 12,
        'NEW_PLAYER_POOL' => 13,
        'COMPENDIUM_MATCHMAKING' => 14,
        'CUSTOM' => 15,
        'CAPTAINS_DRAFT' => 16,
        'BALANCED_DRAFT' => 17,
        'ABILITY_DRAFT' => 18,
        'EVENT' => 19,
        'ALL_RANDOM_DEATH_MATCH' => 20,
        'SOLO_MID_1V1' => 21,
        'RANKED_ALL_PICK' => 22,
        'TURBO' => 23,
        'MUTATION' => 24,
        'COACHES_CHALLENGE' => 25,
    ];

    /**
     * @var array<int, string>
     */
    private const UNSUPPORTED_DRAFT_HEROES = [
        145 => 'Kez',
    ];

    /**
     * @var array<int, string>
     */
    private const BRACKET_IDS = [
        1 => 'HERALD',
        2 => 'GUARDIAN',
        3 => 'CRUSADER',
        4 => 'ARCHON',
        5 => 'LEGEND',
        6 => 'ANCIENT',
        7 => 'DIVINE',
        8 => 'IMMORTAL',
    ];

    /**
     * @var array<int, string>
     */
    private const BRACKET_BASIC_IDS = [
        1 => 'HERALD_GUARDIAN',
        2 => 'HERALD_GUARDIAN',
        3 => 'CRUSADER_ARCHON',
        4 => 'CRUSADER_ARCHON',
        5 => 'LEGEND_ANCIENT',
        6 => 'LEGEND_ANCIENT',
        7 => 'DIVINE_IMMORTAL',
        8 => 'DIVINE_IMMORTAL',
    ];

    public function __construct(protected Api $api) {}

    public function getLeagueMatches(int $leagueId, int $take = 20, int $skip = 0): array
    {
        $query = <<<'GRAPHQL'
query LeagueMatches($leagueId: Int!, $request: LeagueMatchesRequestType!) {
  league(id: $leagueId) {
    id
    name
    matches(request: $request) {
      id
      didRadiantWin
      durationSeconds
      startDateTime
      leagueId
      radiantTeamId
      direTeamId
      players {
        steamAccountId
        heroId
        isRadiant
        kills
        deaths
        assists
        position
      }
    }
  }
}
GRAPHQL;

        $data = $this->api->query($query, [
            'leagueId' => $leagueId,
            'request' => [
                'take' => $take,
                'skip' => $skip,
            ],
        ]);

        return (array) data_get($data, 'league.matches', []);
    }

    public function getMatchById(int $matchId): array
    {
        $query = <<<'GRAPHQL'
query MatchById($matchId: Long!) {
  match(id: $matchId) {
    id
    didRadiantWin
    durationSeconds
    startDateTime
    gameMode
    gameVersionId
    lobbyType
    radiantTeamId
    direTeamId
    players {
      steamAccountId
      heroId
      isRadiant
      kills
      deaths
      assists
      position
      imp
    }
    pickBans {
      isPick
      heroId
      isRadiant
      bannedHeroId
      order
    }
  }
}
GRAPHQL;

        $data = $this->api->query($query, [
            'matchId' => $matchId,
        ]);

        return (array) data_get($data, 'match', []);
    }

    public function getDraftFromMatchId(int $matchId): array
    {
        $match = $this->getMatchById($matchId);
        $draftRequest = $this->buildDraftRequestFromMatch($matchId, $match);
        $draft = $this->getDraft($draftRequest);

        return [
            'formatted' => $this->buildDraftSummary($matchId, $match, $draft),
            'request' => $draftRequest,
            'raw' => $draft,
        ];
    }

    public function getRoshFromMatchId(int $matchId): array
    {
        $match = $this->getRoshMatchContextById($matchId);
        $roshRequest = $this->buildRoshRequestFromMatch($matchId, $match);
        $rosh = $this->getRosh($roshRequest);

        return [
            'formatted' => $this->buildRoshSummary($matchId, $match, $roshRequest),
            'request' => $roshRequest,
            'raw' => [
                'match' => $match,
                'analysis' => $rosh,
            ],
        ];
    }

    public function getProPlayers(): array
    {
        $query = <<<'GRAPHQL'
query ProPlayers {
  constants {
    proSteamAccounts {
      id
      name
      realName
      isPro
      teamId
      position
      countries
    }
  }
}
GRAPHQL;

        $data = $this->api->query($query);

        return (array) data_get($data, 'constants.proSteamAccounts', []);
    }

    public function getDraft(array $request): array
    {
        $query = <<<'GRAPHQL'
query Draft($request: PlusDraftRequestType!) {
  plus {
    draft(request: $request) {
      midOutcome
      safeOutcome
      offOutcome
      winValues
      durationValues
      players {
        slot
        position
        positionValues
        heroes {
          heroId
          pickValue
          winValues
          score
          letter
        }
      }
    }
  }
}
GRAPHQL;

        $data = $this->api->query($query, [
            'request' => $request,
        ]);

        return (array) data_get($data, 'plus.draft', []);
    }

    /**
     * @param  array{
     *     match: array{operationName:string, variables:array{matchId:int}},
     *     analysis: array{
     *         bracket:string,
     *         bracketBasicIds:string,
     *         week:int,
     *         operations:array<int, array{key:string, operationName:string, variables:array<string, int|string>}>
     *     }
     * }  $request
     * @return array{
     *     heroes_meta_positions:array<string, mixed>,
     *     hero_stats_by_time_global:array<string, mixed>,
     *     hero_stats_by_time_bracket:array<string, mixed>,
     *     synergy:array<string, mixed>
     * }
     */
    public function getRosh(array $request): array
    {
        $week = (int) data_get($request, 'analysis.week');
        $bracketBasicId = (string) data_get($request, 'analysis.bracketBasicIds');

        return [
            'heroes_meta_positions' => $this->getRoshHeroesMetaPositionsByWeek($bracketBasicId, $week),
            'hero_stats_by_time_global' => $this->getRoshHeroStatsByTime($week),
            'hero_stats_by_time_bracket' => $this->getRoshHeroStatsByTime($week, $bracketBasicId),
            'synergy' => $this->getRoshSynergy($bracketBasicId, $week),
        ];
    }

    /**
     * @return array{
     *     matchId:int,
     *     gameMode:int,
     *     gameVersionId:int,
     *     players:array<int, array{slot:int, heroId:int, steamAccountId?:int, position?:string}>,
     *     bans?:array<int, int>
     * }
     */
    public function buildDraftRequestFromMatchId(int $matchId): array
    {
        $match = $this->getMatchById($matchId);

        return $this->buildDraftRequestFromMatch($matchId, $match);
    }

    /**
     * @param  array<string, mixed>  $match
     * @return array{
     *     matchId:int,
     *     gameMode:int,
     *     gameVersionId:int,
     *     players:array<int, array{slot:int, heroId:int, steamAccountId?:int}>,
     *     bans?:array<int, int>
     * }
     */
    private function buildDraftRequestFromMatch(int $matchId, array $match): array
    {
        $gameMode = $this->mapGameModeToId((string) ($match['gameMode'] ?? ''));
        $gameVersionId = data_get($match, 'gameVersionId');

        if (! is_int($gameVersionId)) {
            throw new \RuntimeException('STRATZ match response does not contain gameVersionId.');
        }

        $radiantPlayers = [];
        $direPlayers = [];

        foreach ((array) ($match['players'] ?? []) as $player) {
            $heroId = data_get($player, 'heroId');

            if (! is_int($heroId)) {
                continue;
            }

            $draftPlayer = [
                'heroId' => $heroId,
            ];

            $steamAccountId = data_get($player, 'steamAccountId');

            if (is_int($steamAccountId)) {
                $draftPlayer['steamAccountId'] = $steamAccountId;
            }

            $position = data_get($player, 'position');

            if (is_string($position) && $position !== '') {
                $draftPlayer['position'] = $position;
            }

            if ((bool) data_get($player, 'isRadiant')) {
                $radiantPlayers[] = $draftPlayer;
            } else {
                $direPlayers[] = $draftPlayer;
            }
        }

        $players = array_merge($radiantPlayers, $direPlayers);

        if (count($players) !== 10) {
            throw new \RuntimeException('STRATZ match response does not contain 10 draft players.');
        }

        $this->ensureDraftHeroesAreSupported($players);

        $draftPlayers = [];

        foreach ($players as $slot => $player) {
            $draftPlayers[] = [
                'slot' => $slot,
                ...$player,
            ];
        }

        $bans = [];

        foreach ((array) ($match['pickBans'] ?? []) as $pickBan) {
            $bannedHeroId = data_get($pickBan, 'bannedHeroId');

            if (is_int($bannedHeroId)) {
                $bans[] = $bannedHeroId;
            }
        }

        $request = [
            'matchId' => $matchId,
            'gameMode' => $gameMode,
            'gameVersionId' => $gameVersionId,
            'players' => $draftPlayers,
        ];

        if ($bans !== []) {
            $request['bans'] = $bans;
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $match
     * @return array{
     *     match: array{operationName:string, variables:array{matchId:int}},
     *     analysis: array{
     *         bracket:string,
     *         bracketBasicIds:string,
     *         week:int,
     *         operations:array<int, array{key:string, operationName:string, variables:array<string, int|string>}>
     *     }
     * }
     */
    private function buildRoshRequestFromMatch(int $matchId, array $match): array
    {
        $bracketValue = data_get($match, 'bracket');
        $week = data_get($match, 'endDateTime');

        if (! is_int($bracketValue)) {
            throw new \RuntimeException('STRATZ ROSH match response does not contain bracket.');
        }

        if (! is_int($week)) {
            throw new \RuntimeException('STRATZ ROSH match response does not contain endDateTime.');
        }

        $bracket = $this->mapBracketToId($bracketValue);
        $bracketBasicId = $this->mapBracketToBasicId($bracketValue);

        return [
            'match' => [
                'operationName' => 'GetMatchPicksBans',
                'variables' => [
                    'matchId' => $matchId,
                ],
            ],
            'analysis' => [
                'bracket' => $bracket,
                'bracketBasicIds' => $bracketBasicId,
                'week' => $week,
                'operations' => [
                    [
                        'key' => 'heroes_meta_positions',
                        'operationName' => 'HeroesMetaPositionsByWeek',
                        'variables' => [
                            'bracketBasicIds' => $bracketBasicId,
                            'week' => $week,
                        ],
                    ],
                    [
                        'key' => 'hero_stats_by_time_global',
                        'operationName' => 'GetHeroStatsByTime',
                        'variables' => [
                            'week' => $week,
                        ],
                    ],
                    [
                        'key' => 'hero_stats_by_time_bracket',
                        'operationName' => 'GetHeroStatsByTime',
                        'variables' => [
                            'bracketBasicIds' => $bracketBasicId,
                            'week' => $week,
                        ],
                    ],
                    [
                        'key' => 'synergy',
                        'operationName' => 'Synergy',
                        'variables' => [
                            'bracketBasicIds' => $bracketBasicId,
                            'matchLimit' => 0,
                            'take' => 200,
                            'currentWeek' => $week,
                            'previousWeek1' => $week - 604800,
                            'previousWeek2' => $week - 1209600,
                            'previousWeek3' => $week - 1814400,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $draft
     * @return array{
     *     match_id:int,
     *     winner:string,
     *     radiant_odds_1:?float,
     *     radiant_odds_2:?float,
     *     dire_odds_1:?float,
     *     dire_odds_2:?float
     * }
     */
    private function buildDraftSummary(int $matchId, array $match, array $draft): array
    {
        $winValues = array_values(array_filter(
            (array) data_get($draft, 'winValues', []),
            static fn (mixed $value): bool => is_numeric($value),
        ));

        $radiantOddsStart = $this->normalizeProbability($winValues[0] ?? null);
        $radiantOddsEnd = $this->normalizeProbability($winValues[array_key_last($winValues)] ?? null);

        return [
            'match_id' => $matchId,
            'winner' => (bool) data_get($match, 'didRadiantWin') ? 'radiant' : 'dire',
            'radiant_odds_1' => $radiantOddsStart,
            'radiant_odds_2' => $radiantOddsEnd,
            'dire_odds_1' => $this->invertProbability($radiantOddsStart),
            'dire_odds_2' => $this->invertProbability($radiantOddsEnd),
        ];
    }

    /**
     * @param  array<string, mixed>  $match
     * @param  array{
     *     analysis: array{
     *         bracket:string,
     *         bracketBasicIds:string,
     *         week:int
     *     }
     * }  $request
     * @return array{
     *     match_id:int,
     *     winner:string,
     *     radiant_team:string,
     *     dire_team:string,
     *     bracket:string,
     *     bracket_basic:string,
     *     date_time:int
     * }
     */
    private function buildRoshSummary(int $matchId, array $match, array $request): array
    {
        return [
            'match_id' => $matchId,
            'winner' => (bool) data_get($match, 'didRadiantWin') ? 'radiant' : 'dire',
            'radiant_team' => (string) data_get($match, 'radiantTeam.name', 'Radiant'),
            'dire_team' => (string) data_get($match, 'direTeam.name', 'Dire'),
            'bracket' => (string) data_get($request, 'analysis.bracket'),
            'bracket_basic' => (string) data_get($request, 'analysis.bracketBasicIds'),
            'date_time' => (int) data_get($request, 'analysis.week'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getRoshMatchContextById(int $matchId): array
    {
        $query = <<<'GRAPHQL'
query GetMatchPicksBans($matchId: Long!) {
  match(id: $matchId) {
    id
    gameMode
    regionId
    durationSeconds
    endDateTime
    lobbyType
    didRadiantWin
    radiantKills
    direKills
    bracket
    radiantTeam {
      id
      name
    }
    direTeam {
      id
      name
    }
    league {
      id
      displayName
    }
    players {
      heroId
      position
    }
    pickBans {
      heroId
      order
      isPick
      isRadiant
      bannedHeroId
      wasBannedSuccessfully
    }
  }
}
GRAPHQL;

        $data = $this->api->query($query, [
            'matchId' => $matchId,
        ]);

        return (array) data_get($data, 'match', []);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRoshHeroesMetaPositionsByWeek(string $bracketBasicId, int $week): array
    {
        $query = <<<'GRAPHQL'
query HeroesMetaPositionsByWeek($bracketBasicIds: [RankBracketBasicEnum], $week: Long, $heroIds: [Short]) {
  heroStats {
    heroesPos_1: stats(
      positionIds: [POSITION_1]
      bracketBasicIds: $bracketBasicIds
      week: $week
      heroIds: $heroIds
    ) {
      heroId
      matchCount
      winCount
    }
    heroesPos_2: stats(
      positionIds: [POSITION_2]
      bracketBasicIds: $bracketBasicIds
      week: $week
      heroIds: $heroIds
    ) {
      heroId
      matchCount
      winCount
    }
    heroesPos_3: stats(
      positionIds: [POSITION_3]
      bracketBasicIds: $bracketBasicIds
      week: $week
      heroIds: $heroIds
    ) {
      heroId
      matchCount
      winCount
    }
    heroesPos_4: stats(
      positionIds: [POSITION_4]
      bracketBasicIds: $bracketBasicIds
      week: $week
      heroIds: $heroIds
    ) {
      heroId
      matchCount
      winCount
    }
    heroesPos_5: stats(
      positionIds: [POSITION_5]
      bracketBasicIds: $bracketBasicIds
      week: $week
      heroIds: $heroIds
    ) {
      heroId
      matchCount
      winCount
    }
    heroes: stats(bracketBasicIds: $bracketBasicIds, week: $week, heroIds: $heroIds) {
      heroId
      matchCount
      winCount
    }
  }
}
GRAPHQL;

        $data = $this->api->query($query, [
            'bracketBasicIds' => $bracketBasicId,
            'week' => $week,
        ]);

        return (array) data_get($data, 'heroStats', []);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRoshHeroStatsByTime(int $week, ?string $bracketBasicId = null): array
    {
        $query = <<<'GRAPHQL'
query GetHeroStatsByTime($bracketBasicIds: [RankBracketBasicEnum], $week: Long) {
  heroStats {
    heroStatsByTime_1: stats(
      bracketBasicIds: $bracketBasicIds
      positionIds: [POSITION_1]
      groupByTime: true
      minTime: 20
      maxTime: 60
      week: $week
    ) {
      heroId
      time
      winCount
      matchCount
    }
    heroStatsByTime_2: stats(
      bracketBasicIds: $bracketBasicIds
      positionIds: [POSITION_2]
      groupByTime: true
      minTime: 20
      maxTime: 60
      week: $week
    ) {
      heroId
      time
      winCount
      matchCount
    }
    heroStatsByTime_3: stats(
      bracketBasicIds: $bracketBasicIds
      positionIds: [POSITION_3]
      groupByTime: true
      minTime: 20
      maxTime: 60
      week: $week
    ) {
      heroId
      time
      winCount
      matchCount
    }
    heroStatsByTime_4: stats(
      bracketBasicIds: $bracketBasicIds
      positionIds: [POSITION_4]
      groupByTime: true
      minTime: 20
      maxTime: 60
      week: $week
    ) {
      heroId
      time
      winCount
      matchCount
    }
    heroStatsByTime_5: stats(
      bracketBasicIds: $bracketBasicIds
      positionIds: [POSITION_5]
      groupByTime: true
      minTime: 20
      maxTime: 60
      week: $week
    ) {
      heroId
      time
      winCount
      matchCount
    }
  }
}
GRAPHQL;

        $variables = [
            'week' => $week,
        ];

        if ($bracketBasicId !== null) {
            $variables['bracketBasicIds'] = $bracketBasicId;
        }

        $data = $this->api->query($query, $variables);

        return (array) data_get($data, 'heroStats', []);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRoshSynergy(string $bracketBasicId, int $week): array
    {
        $query = <<<'GRAPHQL'
query Synergy(
  $bracketBasicIds: [RankBracketBasicEnum]
  $matchLimit: Int
  $take: Int
  $currentWeek: Long!
  $previousWeek1: Long!
  $previousWeek2: Long!
  $previousWeek3: Long!
  $heroIds: [Short]
) {
  heroStats {
    matchUp_Prev_Week_1: matchUp(
      bracketBasicIds: $bracketBasicIds
      matchLimit: $matchLimit
      take: $take
      week: $currentWeek
      heroIds: $heroIds
    ) {
      heroId
      vs {
        heroId2
        synergy
        matchCount
      }
      with {
        heroId2
        synergy
        matchCount
      }
    }
    matchUp_Prev_Week_2: matchUp(
      bracketBasicIds: $bracketBasicIds
      matchLimit: $matchLimit
      take: $take
      week: $previousWeek1
      heroIds: $heroIds
    ) {
      heroId
      vs {
        heroId2
        synergy
        matchCount
      }
      with {
        heroId2
        synergy
        matchCount
      }
    }
    matchUp_Prev_Week_3: matchUp(
      bracketBasicIds: $bracketBasicIds
      matchLimit: $matchLimit
      take: $take
      week: $previousWeek2
      heroIds: $heroIds
    ) {
      heroId
      vs {
        heroId2
        synergy
        matchCount
      }
      with {
        heroId2
        synergy
        matchCount
      }
    }
    matchUp_Prev_Week_4: matchUp(
      bracketBasicIds: $bracketBasicIds
      matchLimit: $matchLimit
      take: $take
      week: $previousWeek3
      heroIds: $heroIds
    ) {
      heroId
      vs {
        heroId2
        synergy
        matchCount
      }
      with {
        heroId2
        synergy
        matchCount
      }
    }
  }
}
GRAPHQL;

        $data = $this->api->query($query, [
            'bracketBasicIds' => $bracketBasicId,
            'matchLimit' => 0,
            'take' => 200,
            'currentWeek' => $week,
            'previousWeek1' => $week - 604800,
            'previousWeek2' => $week - 1209600,
            'previousWeek3' => $week - 1814400,
        ]);

        return (array) data_get($data, 'heroStats', []);
    }

    private function mapGameModeToId(string $gameMode): int
    {
        if (isset(self::GAME_MODE_IDS[$gameMode])) {
            return self::GAME_MODE_IDS[$gameMode];
        }

        throw new \RuntimeException("Unsupported STRATZ game mode '{$gameMode}'.");
    }

    private function mapBracketToId(int $bracket): string
    {
        if (isset(self::BRACKET_IDS[$bracket])) {
            return self::BRACKET_IDS[$bracket];
        }

        throw new \RuntimeException("Unsupported STRATZ bracket '{$bracket}'.");
    }

    private function mapBracketToBasicId(int $bracket): string
    {
        if (isset(self::BRACKET_BASIC_IDS[$bracket])) {
            return self::BRACKET_BASIC_IDS[$bracket];
        }

        throw new \RuntimeException("Unsupported STRATZ bracket '{$bracket}'.");
    }

    /**
     * @param  array<int, array{heroId:int, steamAccountId?:int, position?:string}>  $players
     */
    private function ensureDraftHeroesAreSupported(array $players): void
    {
        $unsupportedHeroes = [];

        foreach ($players as $player) {
            $heroId = $player['heroId'];

            if (isset(self::UNSUPPORTED_DRAFT_HEROES[$heroId])) {
                $unsupportedHeroes[$heroId] = self::UNSUPPORTED_DRAFT_HEROES[$heroId];
            }
        }

        if ($unsupportedHeroes === []) {
            return;
        }

        $heroTitles = implode(', ', array_values($unsupportedHeroes));

        throw new \RuntimeException("STRATZ Plus Draft currently does not support these heroes: {$heroTitles}.");
    }

    private function normalizeProbability(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 4);
    }

    private function invertProbability(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round(1 - $value, 4);
    }
}
