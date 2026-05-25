<?php

namespace App\Services\Stratz;

class StratzService
{
    private const ROSH_MIN_TIME = 20;

    private const ROSH_MAX_TIME = 60;

    private const ROSH_GRAPH_WINDOW_RADIUS = 1;

    private const ROSH_HERO_BASE_PRIOR_MATCH_COUNT = 500;

    private const ROSH_HERO_TEMPO_PRIOR_MATCH_COUNT = 500;

    private const ROSH_HERO_TEMPO_WEIGHT = 0.35;

    private const ROSH_SYNERGY_RELIABILITY_MATCH_COUNT = 100;

    private const ROSH_SYNERGY_ADJUSTMENT_CAP = 15.0;

    private const ROSH_PLAYER_IMPACT_CAP = 1.5;

    private const ROSH_SIDE_WINRATE_BIAS = 0.037;

    private const ROSH_TEAM_PLAYER_ADJUSTMENT_CAP = 2.5;

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
        $minuteTable = $this->buildRoshMinuteTable($match, $rosh);

        return [
            'formatted' => $this->buildRoshSummary($matchId, $match, $roshRequest, $minuteTable),
            'minute_table' => $minuteTable,
            'request' => $roshRequest,
            'raw' => [
                'match' => $match,
                'analysis_summary' => $this->buildRoshRawAnalysisSummary($rosh),
            ],
        ];
    }

    /**
     * @param  array{
     *     radiant_team:string,
     *     dire_team:string,
     *     radiant_heroes:list<int>,
     *     dire_heroes:list<int>,
     *     consider_players?:bool,
     *     radiant_players?:list<array<string, mixed>|null>,
     *     dire_players?:list<array<string, mixed>|null>
     * }  $payload
     */
    public function getRoshFromHeroes(array $payload): array
    {
        $week = now()->timestamp;
        $match = $this->buildRoshHeroMatchContext($payload, $week);

        if ((bool) data_get($match, 'considerPlayers')) {
            $match = $this->hydrateRoshPlayerHeroHighlights($match);
        }

        $roshRequest = $this->buildRoshRequestFromHeroes($match, $week);
        $rosh = $this->getRosh($roshRequest);
        $minuteTable = $this->buildRoshMinuteTable($match, $rosh);

        return [
            'formatted' => $this->buildRoshSummary('LIVE', $match, $roshRequest, $minuteTable),
            'minute_table' => $minuteTable,
            'request' => $roshRequest,
            'raw' => [
                'match' => $match,
                'analysis_summary' => array_merge(
                    $this->buildRoshRawAnalysisSummary($rosh),
                    [
                        'player_hero_highlights' => $this->buildRoshPlayerAnalysisSummary($match),
                    ],
                ),
            ],
        ];
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
     *         heroIds:list<int>,
     *         operations:array<int, array{key:string, operationName:string, variables:array<string, mixed>}>
     *     }
     * }  $request
     * @return array{
     *     heroes_meta_positions:array<string, mixed>,
     *     hero_stats_by_time_bracket:array<string, mixed>,
     *     synergy:array<string, mixed>
     * }
     */
    public function getRosh(array $request): array
    {
        $week = (int) data_get($request, 'analysis.week');
        $bracketBasicId = (string) data_get($request, 'analysis.bracketBasicIds');
        $heroIds = array_values(array_filter(
            (array) data_get($request, 'analysis.heroIds', []),
            static fn (mixed $heroId): bool => is_int($heroId),
        ));

        return [
            'heroes_meta_positions' => $this->getRoshHeroesMetaPositionsByWeek($bracketBasicId, $week, $heroIds),
            'hero_stats_by_time_bracket' => $this->getRoshHeroStatsByTime($week, $bracketBasicId, $heroIds),
            'synergy' => $this->getRoshSynergy($bracketBasicId, $week, $heroIds),
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
     *         heroIds:list<int>,
     *         operations:array<int, array{key:string, operationName:string, variables:array<string, mixed>}>
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
        $picks = $this->extractRoshPicksFromMatch($match);
        $heroIds = $this->getRoshHeroIdsFromPicks($picks['radiant'], $picks['dire']);

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
                'heroIds' => $heroIds,
                'operations' => [
                    [
                        'key' => 'heroes_meta_positions',
                        'operationName' => 'HeroesMetaPositionsByWeek',
                        'variables' => [
                            'bracketBasicIds' => $bracketBasicId,
                            'week' => $week,
                            'heroIds' => $heroIds,
                        ],
                    ],
                    [
                        'key' => 'hero_stats_by_time_bracket',
                        'operationName' => 'GetHeroStatsByTime',
                        'variables' => [
                            'bracketBasicIds' => $bracketBasicId,
                            'week' => $week,
                            'heroIds' => $heroIds,
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
                            'heroIds' => $heroIds,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $match
     * @return array{
     *     input: array{
     *         mode:string,
     *         matchId:string,
     *         radiantTeam:string,
     *         direTeam:string,
     *         radiantHeroes:list<int>,
     *         direHeroes:list<int>
     *     },
     *     analysis: array{
     *         bracket:string,
     *         bracketBasicIds:string,
     *         week:int,
     *         heroIds:list<int>,
     *         operations:array<int, array{key:string, operationName:string, variables:array<string, mixed>}>
     *     }
     * }
     */
    private function buildRoshRequestFromHeroes(array $match, int $week): array
    {
        $bracket = $this->mapBracketToId(8);
        $bracketBasicId = $this->mapBracketToBasicId(8);
        $considerPlayers = (bool) data_get($match, 'considerPlayers');
        $picks = $this->extractRoshPicksFromMatch($match);
        $radiantHeroIds = array_column($picks['radiant'], 'heroId');
        $direHeroIds = array_column($picks['dire'], 'heroId');
        $heroIds = $this->getRoshHeroIdsFromPicks($picks['radiant'], $picks['dire']);

        return [
            'input' => [
                'mode' => 'heroes',
                'matchId' => 'LIVE',
                'radiantTeam' => (string) data_get($match, 'radiantTeam.name', 'Radiant'),
                'direTeam' => (string) data_get($match, 'direTeam.name', 'Dire'),
                'considerPlayers' => $considerPlayers,
                'radiantHeroes' => $radiantHeroIds,
                'direHeroes' => $direHeroIds,
                ...($considerPlayers ? [
                    'radiantPlayers' => $this->buildRoshRequestPlayersFromMatch($match, true),
                    'direPlayers' => $this->buildRoshRequestPlayersFromMatch($match, false),
                ] : []),
            ],
            'analysis' => [
                'bracket' => $bracket,
                'bracketBasicIds' => $bracketBasicId,
                'week' => $week,
                'heroIds' => $heroIds,
                'operations' => [
                    [
                        'key' => 'heroes_meta_positions',
                        'operationName' => 'HeroesMetaPositionsByWeek',
                        'variables' => [
                            'bracketBasicIds' => $bracketBasicId,
                            'week' => $week,
                            'heroIds' => $heroIds,
                        ],
                    ],
                    [
                        'key' => 'hero_stats_by_time_bracket',
                        'operationName' => 'GetHeroStatsByTime',
                        'variables' => [
                            'bracketBasicIds' => $bracketBasicId,
                            'week' => $week,
                            'heroIds' => $heroIds,
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
                            'heroIds' => $heroIds,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $match
     * @return list<array<string, mixed>|null>
     */
    private function buildRoshRequestPlayersFromMatch(array $match, bool $isRadiant): array
    {
        $players = array_values(array_filter(
            (array) data_get($match, 'players', []),
            static fn (mixed $player): bool => (bool) data_get($player, 'isRadiant') === $isRadiant,
        ));

        return array_map(static function (array $player): ?array {
            $steamAccountId = data_get($player, 'steamAccountId');

            if (! is_int($steamAccountId)) {
                return null;
            }

            return [
                'steamAccountId' => $steamAccountId,
                'playerName' => data_get($player, 'playerName'),
                'proName' => data_get($player, 'proName'),
                'teamName' => data_get($player, 'teamName'),
                'isAnonymous' => data_get($player, 'isAnonymous'),
                'isStratzPublic' => data_get($player, 'isStratzPublic'),
            ];
        }, $players);
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

        $radiantOddsStart = $this->applyRoshSideWinRateBias(
            $this->normalizeProbability($winValues[0] ?? null),
        );
        $radiantOddsEnd = $this->applyRoshSideWinRateBias(
            $this->normalizeProbability($winValues[array_key_last($winValues)] ?? null),
        );

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
     *     match_id:int|string,
     *     winner:string,
     *     radiant_team:string,
     *     dire_team:string,
     *     bracket:string,
     *     bracket_basic:string,
     *     date_time:int,
     *     radiant_odds_1:?float,
     *     radiant_odds_2:?float,
     *     dire_odds_1:?float,
     *     dire_odds_2:?float
     * }
     */
    private function buildRoshSummary(int|string $matchId, array $match, array $request, array $minuteTable): array
    {
        $firstMinute = $minuteTable[0] ?? null;
        $lastMinute = $minuteTable[array_key_last($minuteTable)] ?? null;
        $radiantOddsStart = $this->normalizePercentMetric(data_get($firstMinute, 'radiant_advantage'));
        $radiantOddsEnd = $this->normalizePercentMetric(data_get($lastMinute, 'radiant_advantage'));
        $direOddsStart = $this->normalizePercentMetric(data_get($firstMinute, 'dire_advantage'));
        $direOddsEnd = $this->normalizePercentMetric(data_get($lastMinute, 'dire_advantage'));
        $didRadiantWin = data_get($match, 'didRadiantWin');
        $winner = is_bool($didRadiantWin)
            ? ($didRadiantWin ? 'radiant' : 'dire')
            : $this->resolveRoshWinnerFromMinuteTable($minuteTable);

        return [
            'match_id' => $matchId,
            'winner' => $winner,
            'radiant_team' => (string) data_get($match, 'radiantTeam.name', 'Radiant'),
            'dire_team' => (string) data_get($match, 'direTeam.name', 'Dire'),
            'bracket' => (string) data_get($request, 'analysis.bracket'),
            'bracket_basic' => (string) data_get($request, 'analysis.bracketBasicIds'),
            'date_time' => (int) data_get($request, 'analysis.week'),
            'radiant_odds_1' => $radiantOddsStart,
            'radiant_odds_2' => $radiantOddsEnd,
            'dire_odds_1' => $direOddsStart,
            'dire_odds_2' => $direOddsEnd,
        ];
    }

    /**
     * @param  array{
     *     radiant_team:string,
     *     dire_team:string,
     *     radiant_heroes:list<int>,
     *     dire_heroes:list<int>,
     *     consider_players?:bool,
     *     radiant_players?:list<array<string, mixed>|null>,
     *     dire_players?:list<array<string, mixed>|null>
     * }  $payload
     * @return array<string, mixed>
     */
    private function buildRoshHeroMatchContext(array $payload, int $week): array
    {
        $players = [];
        $pickBans = [];
        $order = 0;
        $considerPlayers = (bool) ($payload['consider_players'] ?? false);

        foreach ((array) ($payload['radiant_heroes'] ?? []) as $index => $heroId) {
            $positionId = $index + 1;

            $players[] = $this->buildRoshHeroPlayerSlot(
                (int) $heroId,
                $positionId,
                true,
                data_get($payload, 'radiant_players.'.$index),
            );
            $pickBans[] = [
                'heroId' => (int) $heroId,
                'order' => $order++,
                'isPick' => true,
                'isRadiant' => true,
                'bannedHeroId' => null,
                'wasBannedSuccessfully' => null,
            ];
        }

        foreach ((array) ($payload['dire_heroes'] ?? []) as $index => $heroId) {
            $positionId = $index + 1;

            $players[] = $this->buildRoshHeroPlayerSlot(
                (int) $heroId,
                $positionId,
                false,
                data_get($payload, 'dire_players.'.$index),
            );
            $pickBans[] = [
                'heroId' => (int) $heroId,
                'order' => $order++,
                'isPick' => true,
                'isRadiant' => false,
                'bannedHeroId' => null,
                'wasBannedSuccessfully' => null,
            ];
        }

        return [
            'id' => 'LIVE',
            'endDateTime' => $week,
            'bracket' => 8,
            'didRadiantWin' => null,
            'considerPlayers' => $considerPlayers,
            'radiantTeam' => [
                'name' => (string) ($payload['radiant_team'] ?? 'Radiant'),
            ],
            'direTeam' => [
                'name' => (string) ($payload['dire_team'] ?? 'Dire'),
            ],
            'players' => $players,
            'pickBans' => $pickBans,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $playerPayload
     * @return array<string, mixed>
     */
    private function buildRoshHeroPlayerSlot(int $heroId, int $positionId, bool $isRadiant, ?array $playerPayload): array
    {
        $steamAccountId = data_get($playerPayload, 'steam_account_id');
        $playerName = data_get($playerPayload, 'name');
        $proName = data_get($playerPayload, 'pro_name');
        $teamName = data_get($playerPayload, 'team_name');
        $isAnonymous = data_get($playerPayload, 'is_anonymous');
        $isStratzPublic = data_get($playerPayload, 'is_stratz_public');

        return [
            'heroId' => $heroId,
            'position' => 'POSITION_'.$positionId,
            'isRadiant' => $isRadiant,
            'steamAccountId' => is_numeric($steamAccountId) ? (int) $steamAccountId : null,
            'playerName' => is_string($playerName) && $playerName !== '' ? $playerName : null,
            'proName' => is_string($proName) && $proName !== '' ? $proName : null,
            'teamName' => is_string($teamName) && $teamName !== '' ? $teamName : null,
            'isAnonymous' => is_bool($isAnonymous) ? $isAnonymous : null,
            'isStratzPublic' => is_bool($isStratzPublic) ? $isStratzPublic : null,
            'playerHeroStats' => null,
            'playerImpact' => 0.0,
            'playerFallbackReason' => is_numeric($steamAccountId) ? null : 'player_not_selected',
            'playerFallbackMessage' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $match
     * @return array<string, mixed>
     */
    private function hydrateRoshPlayerHeroHighlights(array $match): array
    {
        $players = (array) data_get($match, 'players', []);
        $variableDefinitions = [];
        $queryRows = [];
        $variables = [];
        $aliasesByIndex = [];

        foreach ($players as $index => $player) {
            $steamAccountId = data_get($player, 'steamAccountId');
            $heroId = data_get($player, 'heroId');

            if (! is_int($steamAccountId)) {
                $players[$index]['playerFallbackReason'] = 'player_not_selected';

                continue;
            }

            if ((bool) data_get($player, 'isAnonymous')) {
                $players[$index]['playerFallbackReason'] = 'player_is_anonymous';

                continue;
            }

            if (! is_int($heroId)) {
                $players[$index]['playerFallbackReason'] = 'hero_not_selected';

                continue;
            }

            $alias = 'player_'.$index;
            $variableDefinitions[] = '$'.$alias.'SteamAccountId: Long!';
            $variableDefinitions[] = '$'.$alias.'HeroId: Short!';
            $queryRows[] = <<<GRAPHQL
    {$alias}: playerHeroHighlight(steamAccountId: \${$alias}SteamAccountId, heroId: \${$alias}HeroId) {
      lastPlayed
      winCount
      matchCount
      impAllTime
      winCountLastMonth
      matchCountLastMonth
      impLastMonth
      winCountLastSixMonths
      matchCountLastSixMonths
      impLastSixMonths
    }
GRAPHQL;
            $variables[$alias.'SteamAccountId'] = $steamAccountId;
            $variables[$alias.'HeroId'] = $heroId;
            $aliasesByIndex[$alias] = $index;
        }

        if ($queryRows === []) {
            return $this->finalizeRoshPlayerAnalysis($match, $players);
        }

        $query = 'query PlayerHeroHighlights('.implode(', ', $variableDefinitions).") {\n".
            "  plus {\n".
            implode("\n", $queryRows)."\n".
            "  }\n".
            '}';

        try {
            $response = $this->api->queryAllowPartial($query, $variables);
        } catch (\Throwable $throwable) {
            return $this->hydrateRoshPlayerHeroHighlightsIndividually(
                $match,
                $players,
                $aliasesByIndex,
                $throwable->getMessage(),
            );
        }

        $plus = (array) data_get($response, 'data.plus', []);
        $batchErrorMessages = $this->mapRoshPlayerHighlightErrorsByAlias(
            (array) data_get($response, 'errors', []),
        );
        $genericBatchErrorMessage = $this->firstRoshPlayerHighlightErrorMessage(
            (array) data_get($response, 'errors', []),
        );
        $aliasesToRetry = [];

        foreach ($aliasesByIndex as $alias => $index) {
            $rawHighlight = data_get($plus, $alias);

            if (is_array($rawHighlight)) {
                $normalizedHighlight = $this->normalizeRoshPlayerHeroHighlight($rawHighlight);

                $players[$index]['playerHeroStats'] = $normalizedHighlight;
                $players[$index]['playerImpact'] = $this->calculateRoshPlayerImpact($normalizedHighlight);
                $players[$index]['playerFallbackReason'] = null;
                $players[$index]['playerFallbackMessage'] = null;

                continue;
            }

            $aliasBatchErrorMessage = $batchErrorMessages[$alias] ?? null;

            if (is_string($aliasBatchErrorMessage) && $aliasBatchErrorMessage !== '') {
                $this->markRoshPlayerHighlightFailure(
                    $players,
                    $index,
                    $this->resolveRoshPlayerHighlightFallbackReason($aliasBatchErrorMessage),
                    $aliasBatchErrorMessage,
                );

                if ($this->shouldRetryRoshPlayerHighlightBatchError($aliasBatchErrorMessage)) {
                    $aliasesToRetry[$alias] = $index;
                }

                continue;
            }

            if (is_string($genericBatchErrorMessage) && $genericBatchErrorMessage !== '') {
                $this->markRoshPlayerHighlightFailure(
                    $players,
                    $index,
                    $this->resolveRoshPlayerHighlightFallbackReason($genericBatchErrorMessage),
                    $genericBatchErrorMessage,
                );
                $aliasesToRetry[$alias] = $index;

                continue;
            }

            $this->markRoshPlayerHighlightFailure($players, $index, 'player_hero_stats_missing');
        }

        if ($aliasesToRetry !== []) {
            return $this->hydrateRoshPlayerHeroHighlightsIndividually(
                $match,
                $players,
                $aliasesToRetry,
                $genericBatchErrorMessage,
            );
        }

        return $this->finalizeRoshPlayerAnalysis($match, $players);
    }

    /**
     * @param  array<string, mixed>  $match
     * @param  array<int, array<string, mixed>>  $players
     * @param  array<string, int>  $aliasesByIndex
     * @return array<string, mixed>
     */
    private function hydrateRoshPlayerHeroHighlightsIndividually(
        array $match,
        array $players,
        array $aliasesByIndex,
        ?string $batchErrorMessage = null,
    ): array {
        $requestErrors = [];
        $resolvedPlayers = count(array_filter(
            $players,
            static fn (mixed $player): bool => is_array(data_get($player, 'playerHeroStats')),
        ));

        foreach ($aliasesByIndex as $index) {
            $steamAccountId = data_get($players, $index.'.steamAccountId');
            $heroId = data_get($players, $index.'.heroId');

            if (! is_int($steamAccountId) || ! is_int($heroId)) {
                $this->markRoshPlayerHighlightFailure($players, $index, 'player_stats_request_failed');

                continue;
            }

            try {
                $rawHighlight = $this->fetchRoshPlayerHeroHighlight($steamAccountId, $heroId);
            } catch (\Throwable $throwable) {
                $fallbackMessage = $throwable->getMessage();

                $this->markRoshPlayerHighlightFailure(
                    $players,
                    $index,
                    $this->resolveRoshPlayerHighlightFallbackReason($fallbackMessage),
                    $fallbackMessage,
                );
                $requestErrors[] = $fallbackMessage;

                continue;
            }

            if (! is_array($rawHighlight)) {
                $this->markRoshPlayerHighlightFailure($players, $index, 'player_hero_stats_missing');

                continue;
            }

            $normalizedHighlight = $this->normalizeRoshPlayerHeroHighlight($rawHighlight);

            $players[$index]['playerHeroStats'] = $normalizedHighlight;
            $players[$index]['playerImpact'] = $this->calculateRoshPlayerImpact($normalizedHighlight);
            $players[$index]['playerFallbackReason'] = null;
            $players[$index]['playerFallbackMessage'] = null;
            $resolvedPlayers++;
        }

        $requestError = null;

        if ($resolvedPlayers === 0 && $requestErrors !== []) {
            $requestError = $requestErrors[0];
        } elseif ($resolvedPlayers === 0) {
            $requestError = $batchErrorMessage;
        }

        return $this->finalizeRoshPlayerAnalysis($match, $players, $requestError);
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @return array<string, string>
     */
    private function mapRoshPlayerHighlightErrorsByAlias(array $errors): array
    {
        $messagesByAlias = [];

        foreach ($errors as $error) {
            $alias = data_get($error, 'path.1');
            $message = data_get($error, 'message');

            if (! is_string($alias) || $alias === '' || ! is_string($message) || $message === '') {
                continue;
            }

            $messagesByAlias[$alias] = $this->normalizeRoshPlayerHighlightErrorMessage($message);
        }

        return $messagesByAlias;
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     */
    private function firstRoshPlayerHighlightErrorMessage(array $errors): ?string
    {
        $message = data_get($errors, '0.message');

        if (! is_string($message) || $message === '') {
            return null;
        }

        return $this->normalizeRoshPlayerHighlightErrorMessage($message);
    }

    private function normalizeRoshPlayerHighlightErrorMessage(string $message): string
    {
        return str_starts_with($message, 'STRATZ ')
            ? $message
            : 'STRATZ GraphQL error: '.$message;
    }

    private function resolveRoshPlayerHighlightFallbackReason(string $message): string
    {
        $normalizedMessage = mb_strtolower($message);

        if (str_contains($normalizedMessage, 'player id is missing or anonymous')) {
            return 'player_missing_or_anonymous_in_stratz';
        }

        return 'player_stats_request_failed';
    }

    private function shouldRetryRoshPlayerHighlightBatchError(string $message): bool
    {
        $normalizedMessage = mb_strtolower($message);

        foreach ([
            'player id is missing or anonymous',
            'unsupported value',
        ] as $permanentFragment) {
            if (str_contains($normalizedMessage, $permanentFragment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $players
     */
    private function markRoshPlayerHighlightFailure(
        array &$players,
        int $index,
        string $fallbackReason,
        ?string $fallbackMessage = null,
    ): void {
        $players[$index]['playerFallbackReason'] = $fallbackReason;
        $players[$index]['playerFallbackMessage'] = $fallbackMessage;
        $players[$index]['playerHeroStats'] = null;
        $players[$index]['playerImpact'] = 0.0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRoshPlayerHeroHighlight(int $steamAccountId, int $heroId): ?array
    {
        $query = <<<'GRAPHQL'
query PlayerHeroHighlight($steamAccountId: Long!, $heroId: Short!) {
  plus {
    playerHeroHighlight(steamAccountId: $steamAccountId, heroId: $heroId) {
      lastPlayed
      winCount
      matchCount
      impAllTime
      winCountLastMonth
      matchCountLastMonth
      impLastMonth
      winCountLastSixMonths
      matchCountLastSixMonths
      impLastSixMonths
    }
  }
}
GRAPHQL;

        $response = $this->api->query($query, [
            'steamAccountId' => $steamAccountId,
            'heroId' => $heroId,
        ]);

        $rawHighlight = data_get($response, 'plus.playerHeroHighlight');

        return is_array($rawHighlight) ? $rawHighlight : null;
    }

    /**
     * @param  array<string, mixed>  $match
     * @param  array<int, array<string, mixed>>  $players
     * @return array<string, mixed>
     */
    private function finalizeRoshPlayerAnalysis(array $match, array $players, ?string $requestError = null): array
    {
        $match['players'] = $players;
        $match['playerAnalysis'] = $this->buildRoshPlayerAnalysis($players, $requestError);

        return $match;
    }

    /**
     * @param  array<string, mixed>  $rawHighlight
     * @return array{
     *     lastPlayed:int|null,
     *     matchCount:int,
     *     winCount:int,
     *     winRate:?float,
     *     impAllTime:?float,
     *     lastMonth:array{matchCount:int, winCount:int, winRate:?float, imp:?float},
     *     lastSixMonths:array{matchCount:int, winCount:int, winRate:?float, imp:?float},
     *     recentWindow:string,
     *     recentMatchCount:int,
     *     recentWinCount:int,
     *     recentWinRate:?float,
     *     recentImp:?float
     * }
     */
    private function normalizeRoshPlayerHeroHighlight(array $rawHighlight): array
    {
        $matchCount = max(0, (int) data_get($rawHighlight, 'matchCount', 0));
        $winCount = max(0, (int) data_get($rawHighlight, 'winCount', 0));
        $matchCountLastMonth = max(0, (int) data_get($rawHighlight, 'matchCountLastMonth', 0));
        $winCountLastMonth = max(0, (int) data_get($rawHighlight, 'winCountLastMonth', 0));
        $matchCountLastSixMonths = max(0, (int) data_get($rawHighlight, 'matchCountLastSixMonths', 0));
        $winCountLastSixMonths = max(0, (int) data_get($rawHighlight, 'winCountLastSixMonths', 0));

        $recentWindow = 'all_time';
        $recentMatchCount = $matchCount;
        $recentWinCount = $winCount;
        $recentImp = is_numeric(data_get($rawHighlight, 'impAllTime'))
            ? (float) data_get($rawHighlight, 'impAllTime')
            : null;

        if ($matchCountLastMonth > 0) {
            $recentWindow = 'last_month';
            $recentMatchCount = $matchCountLastMonth;
            $recentWinCount = $winCountLastMonth;
            $recentImp = is_numeric(data_get($rawHighlight, 'impLastMonth'))
                ? (float) data_get($rawHighlight, 'impLastMonth')
                : null;
        } elseif ($matchCountLastSixMonths > 0) {
            $recentWindow = 'last_six_months';
            $recentMatchCount = $matchCountLastSixMonths;
            $recentWinCount = $winCountLastSixMonths;
            $recentImp = is_numeric(data_get($rawHighlight, 'impLastSixMonths'))
                ? (float) data_get($rawHighlight, 'impLastSixMonths')
                : null;
        }

        return [
            'lastPlayed' => is_numeric(data_get($rawHighlight, 'lastPlayed'))
                ? (int) data_get($rawHighlight, 'lastPlayed')
                : null,
            'matchCount' => $matchCount,
            'winCount' => $winCount,
            'winRate' => $this->calculateWinRateFromCounts($winCount, $matchCount),
            'impAllTime' => is_numeric(data_get($rawHighlight, 'impAllTime'))
                ? round((float) data_get($rawHighlight, 'impAllTime'), 2)
                : null,
            'lastMonth' => [
                'matchCount' => $matchCountLastMonth,
                'winCount' => $winCountLastMonth,
                'winRate' => $this->calculateWinRateFromCounts($winCountLastMonth, $matchCountLastMonth),
                'imp' => is_numeric(data_get($rawHighlight, 'impLastMonth'))
                    ? round((float) data_get($rawHighlight, 'impLastMonth'), 2)
                    : null,
            ],
            'lastSixMonths' => [
                'matchCount' => $matchCountLastSixMonths,
                'winCount' => $winCountLastSixMonths,
                'winRate' => $this->calculateWinRateFromCounts($winCountLastSixMonths, $matchCountLastSixMonths),
                'imp' => is_numeric(data_get($rawHighlight, 'impLastSixMonths'))
                    ? round((float) data_get($rawHighlight, 'impLastSixMonths'), 2)
                    : null,
            ],
            'recentWindow' => $recentWindow,
            'recentMatchCount' => $recentMatchCount,
            'recentWinCount' => $recentWinCount,
            'recentWinRate' => $this->calculateWinRateFromCounts($recentWinCount, $recentMatchCount),
            'recentImp' => $recentImp !== null ? round($recentImp, 2) : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $playerHeroStats
     */
    private function calculateRoshPlayerImpact(?array $playerHeroStats): float
    {
        if ($playerHeroStats === null) {
            return 0.0;
        }

        $matchCount = max(0, (int) data_get($playerHeroStats, 'matchCount', 0));
        $winRate = data_get($playerHeroStats, 'winRate');

        if (! is_numeric($winRate) || $matchCount === 0) {
            return 0.0;
        }

        $recentMatchCount = max(0, (int) data_get($playerHeroStats, 'recentMatchCount', 0));
        $recentWinRate = data_get($playerHeroStats, 'recentWinRate');
        $overallDiff = (float) $winRate - 50.0;
        $recentDiff = is_numeric($recentWinRate)
            ? (float) $recentWinRate - 50.0
            : $overallDiff;
        $overallConfidence = $this->clampFloat($matchCount / 30, 0.0, 1.0);
        $recentConfidence = $this->clampFloat($recentMatchCount / 10, 0.0, 1.0);
        $impValue = data_get($playerHeroStats, 'recentImp');

        if (! is_numeric($impValue)) {
            $impValue = data_get($playerHeroStats, 'impAllTime', 0.0);
        }

        $impScore = $this->clampFloat(((float) $impValue) / 20.0, -1.2, 1.2);
        $impact = ($overallDiff * $overallConfidence * 0.03)
            + ($recentDiff * $recentConfidence * 0.05)
            + ($impScore * 0.35);

        return round(
            $this->clampFloat($impact, -self::ROSH_PLAYER_IMPACT_CAP, self::ROSH_PLAYER_IMPACT_CAP),
            2,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $players
     * @return array<string, mixed>
     */
    private function buildRoshPlayerAnalysis(array $players, ?string $requestError = null): array
    {
        $selectedCount = 0;
        $resolvedCount = 0;
        $fallbackCount = 0;
        $radiantTotalImpact = 0.0;
        $direTotalImpact = 0.0;

        foreach ($players as $player) {
            $steamAccountId = data_get($player, 'steamAccountId');
            $playerImpact = is_numeric(data_get($player, 'playerImpact'))
                ? (float) data_get($player, 'playerImpact')
                : 0.0;

            if ((bool) data_get($player, 'isRadiant')) {
                $radiantTotalImpact += $playerImpact;
            } else {
                $direTotalImpact += $playerImpact;
            }

            if (! is_int($steamAccountId)) {
                continue;
            }

            $selectedCount++;

            if (is_array(data_get($player, 'playerHeroStats'))) {
                $resolvedCount++;
            }

            $fallbackReason = data_get($player, 'playerFallbackReason');

            if (is_string($fallbackReason) && $fallbackReason !== '') {
                $fallbackCount++;
            }
        }

        $netAdjustment = round(
            $this->clampFloat(
                ($radiantTotalImpact - $direTotalImpact) / 5,
                -self::ROSH_TEAM_PLAYER_ADJUSTMENT_CAP,
                self::ROSH_TEAM_PLAYER_ADJUSTMENT_CAP,
            ),
            1,
        );

        return [
            'enabled' => true,
            'source' => 'plus.playerHeroHighlight',
            'selectedCount' => $selectedCount,
            'resolvedCount' => $resolvedCount,
            'fallbackCount' => $fallbackCount,
            'radiantTotalImpact' => round($radiantTotalImpact, 2),
            'direTotalImpact' => round($direTotalImpact, 2),
            'netAdjustment' => $netAdjustment,
            'requestError' => $requestError,
        ];
    }

    /**
     * @param  array<string, mixed>  $match
     * @param  array{
     *     heroes_meta_positions:array<string, mixed>,
     *     hero_stats_by_time_bracket:array<string, mixed>,
     *     synergy:array<string, mixed>
     * }  $analysis
     * @return list<array{
     *     minute:int,
     *     time_start:int,
     *     time_end:int,
     *     advantage_side:string,
     *     advantage_percent:float,
     *     radiant_advantage:float,
     *     dire_advantage:float,
     *     match_percentage:float,
     *     win_rate_graph:float,
     *     hero_adjustment:float,
     *     hero_base_adjustment:float,
     *     hero_tempo_adjustment:float,
     *     synergy_adjustment:float,
     *     player_adjustment:float
     * }>
     */
    private function buildRoshMinuteTable(array $match, array $analysis): array
    {
        $picks = $this->extractRoshPicksFromMatch($match);
        $radiantPicks = $picks['radiant'];
        $direPicks = $picks['dire'];
        $pickCount = count($radiantPicks) + count($direPicks);

        if ($pickCount === 0) {
            return [];
        }

        $heroPositionData = $this->buildRoshHeroPositionData(
            (array) ($analysis['heroes_meta_positions'] ?? []),
        );
        $heroBaseAdjustment = $this->calculateRoshHeroBaseAdjustment(
            $radiantPicks,
            $direPicks,
            $heroPositionData,
        );

        $computedGraphData = $this->buildRoshComputedGraphData(
            (array) ($analysis['hero_stats_by_time_bracket'] ?? []),
            $heroPositionData,
        );

        if ($computedGraphData === []) {
            return [];
        }

        $synergyData = $this->buildRoshSynergyData((array) ($analysis['synergy'] ?? []));
        $synergyOffset = $this->calculateRoshSynergyOffset(
            $radiantPicks,
            $direPicks,
            $synergyData,
        );
        $playerAdjustment = $this->calculateRoshPlayerAdjustment($match);

        ksort($computedGraphData);

        $minuteTable = [];

        foreach ($computedGraphData as $bucket) {
            $radiantTempoTotal = 0.0;
            $direTempoTotal = 0.0;
            $minuteMatchCount = 0;
            $totalMatchCount = 0;

            foreach ($radiantPicks as $pick) {
                $heroStats = data_get(
                    $bucket,
                    'heroes.'.$pick['positionId'].'.'.$pick['heroId'],
                );

                if (! is_array($heroStats)) {
                    continue;
                }

                $radiantTempoTotal += (float) data_get($heroStats, 'tempo_effect', 0.0);
                $minuteMatchCount += (int) data_get($heroStats, 'match_count', 0);
                $totalMatchCount += (int) data_get($heroStats, 'total_match_count', 0);
            }

            foreach ($direPicks as $pick) {
                $heroStats = data_get(
                    $bucket,
                    'heroes.'.$pick['positionId'].'.'.$pick['heroId'],
                );

                if (! is_array($heroStats)) {
                    continue;
                }

                $direTempoTotal += (float) data_get($heroStats, 'tempo_effect', 0.0);
                $minuteMatchCount += (int) data_get($heroStats, 'match_count', 0);
                $totalMatchCount += (int) data_get($heroStats, 'total_match_count', 0);
            }

            $heroTempoAdjustment = $this->calculateRoshTeamAverageDifference(
                $radiantTempoTotal,
                count($radiantPicks),
                $direTempoTotal,
                count($direPicks),
            );
            $heroAdjustment = $heroBaseAdjustment + $heroTempoAdjustment;
            $winRateGraph = round($heroAdjustment + $synergyOffset + $playerAdjustment, 1);

            $matchPercentage = $totalMatchCount > 0
                ? round(($minuteMatchCount / $totalMatchCount) * 100, 1)
                : 0.0;

            $minuteTable[] = [
                'minute' => (int) data_get($bucket, 'time'),
                'time_start' => (int) data_get($bucket, 'time_start'),
                'time_end' => (int) data_get($bucket, 'time_end'),
                'advantage_side' => $winRateGraph > 0 ? 'radiant' : ($winRateGraph < 0 ? 'dire' : 'even'),
                'advantage_percent' => round(abs($winRateGraph), 1),
                'radiant_advantage' => $winRateGraph > 0 ? round($winRateGraph, 1) : 0.0,
                'dire_advantage' => $winRateGraph < 0 ? round(abs($winRateGraph), 1) : 0.0,
                'match_percentage' => $matchPercentage,
                'win_rate_graph' => $winRateGraph,
                'hero_adjustment' => round($heroAdjustment, 1),
                'hero_base_adjustment' => round($heroBaseAdjustment, 1),
                'hero_tempo_adjustment' => round($heroTempoAdjustment, 1),
                'synergy_adjustment' => round($synergyOffset, 1),
                'player_adjustment' => $playerAdjustment,
            ];
        }

        return $minuteTable;
    }

    /**
     * @param  array<string, mixed>  $match
     * @return array{
     *     radiant:list<array{heroId:int, positionId:int}>,
     *     dire:list<array{heroId:int, positionId:int}>
     * }
     */
    private function extractRoshPicksFromMatch(array $match): array
    {
        $positionIdsByHeroId = [];

        foreach ((array) ($match['players'] ?? []) as $player) {
            $heroId = data_get($player, 'heroId');
            $positionId = $this->extractRoshPositionId(data_get($player, 'position'));

            if (! is_int($heroId) || $positionId === null) {
                continue;
            }

            $positionIdsByHeroId[$heroId] = $positionId;
        }

        $radiantPicks = [];
        $direPicks = [];
        $pickRows = [];

        foreach ((array) ($match['pickBans'] ?? []) as $pickBan) {
            $heroId = data_get($pickBan, 'heroId');
            $positionId = is_int($heroId) ? ($positionIdsByHeroId[$heroId] ?? null) : null;

            if (! (bool) data_get($pickBan, 'isPick') || ! is_int($heroId) || $positionId === null) {
                continue;
            }

            $pickRows[] = [
                'heroId' => $heroId,
                'positionId' => $positionId,
                'isRadiant' => (bool) data_get($pickBan, 'isRadiant'),
                'order' => (int) data_get($pickBan, 'order', PHP_INT_MAX),
            ];
        }

        usort(
            $pickRows,
            static fn (array $left, array $right): int => $left['order'] <=> $right['order'],
        );

        foreach ($pickRows as $pickRow) {
            $pick = [
                'heroId' => $pickRow['heroId'],
                'positionId' => $pickRow['positionId'],
            ];

            if ($pickRow['isRadiant']) {
                $radiantPicks[] = $pick;
            } else {
                $direPicks[] = $pick;
            }
        }

        if ($radiantPicks !== [] || $direPicks !== []) {
            return [
                'radiant' => $radiantPicks,
                'dire' => $direPicks,
            ];
        }

        $fallbackPlayers = [];

        foreach ((array) ($match['players'] ?? []) as $player) {
            $heroId = data_get($player, 'heroId');
            $positionId = $this->extractRoshPositionId(data_get($player, 'position'));

            if (! is_int($heroId) || $positionId === null) {
                continue;
            }

            $fallbackPlayers[] = [
                'heroId' => $heroId,
                'positionId' => $positionId,
            ];
        }

        return [
            'radiant' => array_slice($fallbackPlayers, 0, 5),
            'dire' => array_slice($fallbackPlayers, 5, 5),
        ];
    }

    private function extractRoshPositionId(mixed $position): ?int
    {
        if (! is_string($position) || ! preg_match('/POSITION_(\d+)/', $position, $matches)) {
            return null;
        }

        $positionId = (int) $matches[1];

        return $positionId >= 1 && $positionId <= 5 ? $positionId : null;
    }

    /**
     * @param  list<array{heroId:int, positionId:int}>  $radiantPicks
     * @param  list<array{heroId:int, positionId:int}>  $direPicks
     * @return list<int>
     */
    private function getRoshHeroIdsFromPicks(array $radiantPicks, array $direPicks): array
    {
        return array_values(array_unique(array_column(
            [...$radiantPicks, ...$direPicks],
            'heroId',
        )));
    }

    /**
     * @param  array<string, mixed>  $rawSynergy
     * @return array{
     *     with:array<int, array<int, array{matchCount:int, synergy:float}>>,
     *     vs:array<int, array<int, array{matchCount:int, synergy:float}>>
     * }
     */
    private function buildRoshSynergyData(array $rawSynergy): array
    {
        $synergyWithData = [];
        $synergyVsData = [];
        $weeks = [];

        foreach (range(1, 4) as $weekIndex) {
            $weeks[] = (array) data_get($rawSynergy, 'matchUp_Prev_Week_'.$weekIndex, []);
        }

        foreach ($weeks as $rows) {
            foreach ($rows as $row) {
                $heroId = data_get($row, 'heroId');

                if (! is_int($heroId)) {
                    continue;
                }

                foreach ((array) data_get($row, 'with', []) as $withRow) {
                    $heroId2 = data_get($withRow, 'heroId2');
                    $matchCount = data_get($withRow, 'matchCount');
                    $synergy = data_get($withRow, 'synergy');

                    if (! is_int($heroId2) || ! is_numeric($matchCount) || ! is_numeric($synergy)) {
                        continue;
                    }

                    $this->mergeRoshSynergyEntry(
                        $synergyWithData,
                        $heroId,
                        $heroId2,
                        (int) $matchCount,
                        (float) $synergy,
                    );
                }

                foreach ((array) data_get($row, 'vs', []) as $vsRow) {
                    $heroId2 = data_get($vsRow, 'heroId2');
                    $matchCount = data_get($vsRow, 'matchCount');
                    $synergy = data_get($vsRow, 'synergy');

                    if (! is_int($heroId2) || ! is_numeric($matchCount) || ! is_numeric($synergy)) {
                        continue;
                    }

                    $this->mergeRoshSynergyEntry(
                        $synergyVsData,
                        $heroId,
                        $heroId2,
                        (int) $matchCount,
                        (float) $synergy,
                    );
                }
            }
        }

        return [
            'with' => $this->applyRoshSynergyReliability($synergyWithData),
            'vs' => $this->applyRoshSynergyReliability($synergyVsData),
        ];
    }

    /**
     * @param  array<int, array<int, array{matchCount:int, synergy:float}>>  &$lookup
     */
    private function mergeRoshSynergyEntry(
        array &$lookup,
        int $heroId,
        int $heroId2,
        int $matchCount,
        float $synergy,
    ): void {
        if (! isset($lookup[$heroId][$heroId2])) {
            $lookup[$heroId][$heroId2] = [
                'matchCount' => 0,
                'synergy' => 0.0,
            ];
        }

        $currentEntry = $lookup[$heroId][$heroId2];

        if ($currentEntry['matchCount'] >= 100) {
            return;
        }

        $totalMatchCount = $currentEntry['matchCount'] + $matchCount;

        if ($totalMatchCount <= 0) {
            return;
        }

        $weightedSynergy = ($currentEntry['synergy'] * ($currentEntry['matchCount'] / $totalMatchCount))
            + ($synergy * ($matchCount / $totalMatchCount));

        $lookup[$heroId][$heroId2] = [
            'matchCount' => $totalMatchCount,
            'synergy' => $weightedSynergy,
        ];
    }

    /**
     * @param  array<int, array<int, array{matchCount:int, synergy:float}>>  $lookup
     * @return array<int, array<int, array{matchCount:int, synergy:float}>>
     */
    private function applyRoshSynergyReliability(array $lookup): array
    {
        foreach ($lookup as $heroId => $entries) {
            foreach ($entries as $heroId2 => $entry) {
                $confidence = $this->clampFloat(
                    $entry['matchCount'] / self::ROSH_SYNERGY_RELIABILITY_MATCH_COUNT,
                    0.0,
                    1.0,
                );

                $lookup[$heroId][$heroId2]['synergy'] = round($entry['synergy'] * $confidence, 2);
            }
        }

        return $lookup;
    }

    /**
     * @param  array<string, mixed>  $heroesMetaPositions
     * @return array<int, array<int, array{
     *     match_count:int,
     *     raw_win_rate_diff:float,
     *     base_effect:float
     * }>>
     */
    private function buildRoshHeroPositionData(array $heroesMetaPositions): array
    {
        $positionData = [];

        foreach (range(1, 5) as $positionId) {
            foreach ((array) data_get($heroesMetaPositions, 'heroesPos_'.$positionId, []) as $row) {
                $heroId = data_get($row, 'heroId');
                $matchCount = data_get($row, 'matchCount');
                $winCount = data_get($row, 'winCount');

                if (! is_int($heroId) || ! is_numeric($matchCount) || ! is_numeric($winCount) || (int) $matchCount <= 0) {
                    continue;
                }

                $matchCount = (int) $matchCount;
                $rawWinRateDiff = (((int) $winCount / $matchCount) * 100) - 50;
                $confidence = $matchCount / ($matchCount + self::ROSH_HERO_BASE_PRIOR_MATCH_COUNT);

                $positionData[$positionId][$heroId] = [
                    'match_count' => $matchCount,
                    'raw_win_rate_diff' => $rawWinRateDiff,
                    'base_effect' => $rawWinRateDiff * $confidence,
                ];
            }
        }

        return $positionData;
    }

    /**
     * @param  list<array{heroId:int, positionId:int}>  $radiantPicks
     * @param  list<array{heroId:int, positionId:int}>  $direPicks
     * @param  array<int, array<int, array{
     *     match_count:int,
     *     raw_win_rate_diff:float,
     *     base_effect:float
     * }>>  $heroPositionData
     */
    private function calculateRoshHeroBaseAdjustment(
        array $radiantPicks,
        array $direPicks,
        array $heroPositionData,
    ): float {
        $radiantTotal = $this->sumRoshHeroPositionEffects($radiantPicks, $heroPositionData);
        $direTotal = $this->sumRoshHeroPositionEffects($direPicks, $heroPositionData);

        return $this->calculateRoshTeamAverageDifference(
            $radiantTotal,
            count($radiantPicks),
            $direTotal,
            count($direPicks),
        );
    }

    /**
     * @param  list<array{heroId:int, positionId:int}>  $picks
     * @param  array<int, array<int, array{
     *     match_count:int,
     *     raw_win_rate_diff:float,
     *     base_effect:float
     * }>>  $heroPositionData
     */
    private function sumRoshHeroPositionEffects(array $picks, array $heroPositionData): float
    {
        $total = 0.0;

        foreach ($picks as $pick) {
            $total += (float) data_get(
                $heroPositionData,
                $pick['positionId'].'.'.$pick['heroId'].'.base_effect',
                0.0,
            );
        }

        return $total;
    }

    private function calculateRoshTeamAverageDifference(
        float $radiantTotal,
        int $radiantPickCount,
        float $direTotal,
        int $direPickCount,
    ): float {
        $radiantAverage = $radiantPickCount > 0 ? $radiantTotal / $radiantPickCount : 0.0;
        $direAverage = $direPickCount > 0 ? $direTotal / $direPickCount : 0.0;

        return $radiantAverage - $direAverage;
    }

    /**
     * @param  array<string, mixed>  $heroStatsByTime
     * @param  array<int, array<int, array{
     *     match_count:int,
     *     raw_win_rate_diff:float,
     *     base_effect:float
     * }>>  $heroPositionData
     * @return array<int, array{
     *     time:int,
     *     time_start:int,
     *     time_end:int,
     *     heroes:array<int, array<int, array{
     *         hero_id:int,
     *         tempo_effect:float,
     *         match_count:int,
     *         total_match_count:int
     *     }>>
     * }>
     */
    private function buildRoshComputedGraphData(array $heroStatsByTime, array $heroPositionData): array
    {
        $computedGraphData = [];

        foreach (range(1, 5) as $positionId) {
            $rows = array_values(array_filter(
                (array) data_get($heroStatsByTime, 'heroStatsByTime_'.$positionId, []),
                static function (mixed $row): bool {
                    return is_array($row)
                        && is_int(data_get($row, 'heroId'))
                        && is_numeric(data_get($row, 'time'))
                        && is_numeric(data_get($row, 'winCount'))
                        && is_numeric(data_get($row, 'matchCount'));
                },
            ));

            usort(
                $rows,
                static fn (array $left, array $right): int => [$left['heroId'], $left['time']] <=> [$right['heroId'], $right['time']],
            );

            $normalizedRows = [];
            $totalMatchCountByHeroId = [];
            $rowCount = count($rows);

            foreach ($rows as $index => $row) {
                $heroId = (int) $row['heroId'];
                $matchCount = (int) $row['matchCount'];
                $winCount = (int) $row['winCount'];
                $nextRow = $rows[$index + 1] ?? null;
                $sameHeroAsNextRow = is_array($nextRow) && (int) data_get($nextRow, 'heroId') === $heroId;
                $minuteMatchCount = $sameHeroAsNextRow
                    ? max(0, $matchCount - (int) data_get($nextRow, 'matchCount', 0))
                    : $matchCount;
                $minuteWinCount = $sameHeroAsNextRow
                    ? max(0, $winCount - (int) data_get($nextRow, 'winCount', 0))
                    : $winCount;

                $totalMatchCountByHeroId[$heroId] = ($totalMatchCountByHeroId[$heroId] ?? 0) + $minuteMatchCount;

                $normalizedRows[] = [
                    'heroId' => $heroId,
                    'time' => (int) $row['time'],
                    'matchCount' => $minuteMatchCount,
                    'winCount' => $minuteWinCount,
                ];
            }

            $normalizedRowCount = count($normalizedRows);

            foreach ($normalizedRows as $index => $row) {
                $heroId = $row['heroId'];
                $time = $row['time'];

                if ($time < self::ROSH_MIN_TIME || $time > self::ROSH_MAX_TIME) {
                    continue;
                }

                $baseWinRateDiff = data_get(
                    $heroPositionData,
                    $positionId.'.'.$heroId.'.raw_win_rate_diff',
                );

                if (! is_numeric($baseWinRateDiff)) {
                    continue;
                }

                if (! isset($computedGraphData[$time])) {
                    $computedGraphData[$time] = [
                        'time' => $time,
                        'time_start' => max(self::ROSH_MIN_TIME, $time - self::ROSH_GRAPH_WINDOW_RADIUS),
                        'time_end' => min(self::ROSH_MAX_TIME, $time + self::ROSH_GRAPH_WINDOW_RADIUS),
                        'heroes' => [],
                    ];
                }

                if (! isset($computedGraphData[$time]['heroes'][$positionId])) {
                    $computedGraphData[$time]['heroes'][$positionId] = [];
                }

                $windowStart = max(0, $index - self::ROSH_GRAPH_WINDOW_RADIUS);
                $windowEnd = min($normalizedRowCount, $index + self::ROSH_GRAPH_WINDOW_RADIUS + 1);
                $windowRows = array_values(array_filter(
                    array_slice($normalizedRows, $windowStart, $windowEnd - $windowStart),
                    static fn (array $candidate): bool => $candidate['heroId'] === $heroId,
                ));

                $windowMatchCount = array_sum(array_column($windowRows, 'matchCount'));
                $windowWinCount = array_sum(array_column($windowRows, 'winCount'));

                if ($windowMatchCount <= 0) {
                    continue;
                }

                $durationWinRateDiff = (($windowWinCount / $windowMatchCount) * 100) - 50;
                $confidence = $windowMatchCount / ($windowMatchCount + self::ROSH_HERO_TEMPO_PRIOR_MATCH_COUNT);

                $computedGraphData[$time]['heroes'][$positionId][$heroId] = [
                    'hero_id' => $heroId,
                    'tempo_effect' => ($durationWinRateDiff - (float) $baseWinRateDiff)
                        * $confidence
                        * self::ROSH_HERO_TEMPO_WEIGHT,
                    'match_count' => $row['matchCount'],
                    'total_match_count' => $totalMatchCountByHeroId[$heroId],
                ];
            }
        }

        return $computedGraphData;
    }

    /**
     * @param  list<array{heroId:int, positionId:int}>  $radiantPicks
     * @param  list<array{heroId:int, positionId:int}>  $direPicks
     * @param  array{
     *     with:array<int, array<int, array{matchCount:int, synergy:float}>>,
     *     vs:array<int, array<int, array{matchCount:int, synergy:float}>>
     * }  $synergyData
     */
    private function calculateRoshSynergyOffset(array $radiantPicks, array $direPicks, array $synergyData): float
    {
        $radiantSynergy = $this->sumRoshTeamPairSynergies(
            $radiantPicks,
            (array) ($synergyData['with'] ?? []),
        );
        $direSynergy = $this->sumRoshTeamPairSynergies(
            $direPicks,
            (array) ($synergyData['with'] ?? []),
        );
        $matchupAdvantage = $this->sumRoshMatchupAdvantages(
            $radiantPicks,
            $direPicks,
            (array) ($synergyData['vs'] ?? []),
        );

        return $this->clampFloat(
            $radiantSynergy - $direSynergy + $matchupAdvantage,
            -self::ROSH_SYNERGY_ADJUSTMENT_CAP,
            self::ROSH_SYNERGY_ADJUSTMENT_CAP,
        );
    }

    /**
     * @param  list<array{heroId:int, positionId:int}>  $teamPicks
     * @param  array<int, array<int, array{matchCount:int, synergy:float}>>  $lookup
     */
    private function sumRoshTeamPairSynergies(array $teamPicks, array $lookup): float
    {
        $synergy = 0.0;
        $pickCount = count($teamPicks);

        for ($leftIndex = 0; $leftIndex < $pickCount; $leftIndex++) {
            for ($rightIndex = $leftIndex + 1; $rightIndex < $pickCount; $rightIndex++) {
                $synergy += $this->averageRoshPairSynergy(
                    $teamPicks[$leftIndex]['heroId'],
                    $teamPicks[$rightIndex]['heroId'],
                    $lookup,
                );
            }
        }

        return $synergy;
    }

    /**
     * @param  list<array{heroId:int, positionId:int}>  $radiantPicks
     * @param  list<array{heroId:int, positionId:int}>  $direPicks
     * @param  array<int, array<int, array{matchCount:int, synergy:float}>>  $lookup
     */
    private function sumRoshMatchupAdvantages(array $radiantPicks, array $direPicks, array $lookup): float
    {
        $advantage = 0.0;

        foreach ($radiantPicks as $radiantPick) {
            foreach ($direPicks as $direPick) {
                $radiantSynergy = data_get($lookup, $radiantPick['heroId'].'.'.$direPick['heroId'].'.synergy');
                $direSynergy = data_get($lookup, $direPick['heroId'].'.'.$radiantPick['heroId'].'.synergy');

                if (is_numeric($radiantSynergy) && is_numeric($direSynergy)) {
                    $advantage += ((float) $radiantSynergy - (float) $direSynergy) / 2;

                    continue;
                }

                if (is_numeric($radiantSynergy)) {
                    $advantage += (float) $radiantSynergy;
                } elseif (is_numeric($direSynergy)) {
                    $advantage -= (float) $direSynergy;
                }
            }
        }

        return $advantage;
    }

    /**
     * @param  array<int, array<int, array{matchCount:int, synergy:float}>>  $lookup
     */
    private function averageRoshPairSynergy(int $heroId, int $heroId2, array $lookup): float
    {
        $leftSynergy = data_get($lookup, $heroId.'.'.$heroId2.'.synergy');
        $rightSynergy = data_get($lookup, $heroId2.'.'.$heroId.'.synergy');

        if (is_numeric($leftSynergy) && is_numeric($rightSynergy)) {
            return ((float) $leftSynergy + (float) $rightSynergy) / 2;
        }

        if (is_numeric($leftSynergy)) {
            return (float) $leftSynergy;
        }

        return is_numeric($rightSynergy) ? (float) $rightSynergy : 0.0;
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
    private function getRoshHeroesMetaPositionsByWeek(string $bracketBasicId, int $week, array $heroIds): array
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
            'heroIds' => $heroIds,
        ]);

        return (array) data_get($data, 'heroStats', []);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRoshHeroStatsByTime(int $week, string $bracketBasicId, array $heroIds): array
    {
        $query = <<<'GRAPHQL'
query GetHeroStatsByTime($bracketBasicIds: [RankBracketBasicEnum], $week: Long, $heroIds: [Short]) {
  heroStats {
    heroStatsByTime_1: stats(
      bracketBasicIds: $bracketBasicIds
      positionIds: [POSITION_1]
      groupByTime: true
      minTime: 20
      maxTime: 62
      week: $week
      heroIds: $heroIds
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
      maxTime: 62
      week: $week
      heroIds: $heroIds
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
      maxTime: 62
      week: $week
      heroIds: $heroIds
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
      maxTime: 62
      week: $week
      heroIds: $heroIds
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
      maxTime: 62
      week: $week
      heroIds: $heroIds
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
            'bracketBasicIds' => $bracketBasicId,
            'heroIds' => $heroIds,
        ];

        $data = $this->api->query($query, $variables);

        return (array) data_get($data, 'heroStats', []);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRoshSynergy(string $bracketBasicId, int $week, array $heroIds): array
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
            'heroIds' => $heroIds,
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

    private function applyRoshSideWinRateBias(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round($this->clampFloat($value + self::ROSH_SIDE_WINRATE_BIAS, 0.0, 1.0), 4);
    }

    private function normalizePercentMetric(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 1);
    }

    private function calculateWinRateFromCounts(int $winCount, int $matchCount): ?float
    {
        if ($matchCount <= 0) {
            return null;
        }

        return round(($winCount / $matchCount) * 100, 1);
    }

    /**
     * @param  array<string, mixed>  $match
     */
    private function calculateRoshPlayerAdjustment(array $match): float
    {
        if (! (bool) data_get($match, 'considerPlayers')) {
            return 0.0;
        }

        return round((float) data_get($match, 'playerAnalysis.netAdjustment', 0.0), 1);
    }

    /**
     * @param  list<array{
     *     minute:int,
     *     time_start:int,
     *     time_end:int,
     *     advantage_side:string,
     *     advantage_percent:float,
     *     radiant_advantage:float,
     *     dire_advantage:float,
     *     match_percentage:float,
     *     win_rate_graph:float
     * }>  $minuteTable
     */
    private function resolveRoshWinnerFromMinuteTable(array $minuteTable): string
    {
        $lastMinute = $minuteTable[array_key_last($minuteTable)] ?? null;
        $radiantAdvantage = (float) data_get($lastMinute, 'radiant_advantage', 0.0);
        $direAdvantage = (float) data_get($lastMinute, 'dire_advantage', 0.0);

        return $direAdvantage > $radiantAdvantage ? 'dire' : 'radiant';
    }

    /**
     * @param  array{
     *     heroes_meta_positions:array<string, mixed>,
     *     hero_stats_by_time_bracket:array<string, mixed>,
     *     synergy:array<string, mixed>
     * }  $analysis
     * @return array<string, mixed>
     */
    private function buildRoshRawAnalysisSummary(array $analysis): array
    {
        return [
            'heroes_meta_positions' => $this->summarizeRoshFlatBuckets(
                (array) ($analysis['heroes_meta_positions'] ?? []),
                [
                    'heroesPos_1',
                    'heroesPos_2',
                    'heroesPos_3',
                    'heroesPos_4',
                    'heroesPos_5',
                    'heroes',
                ],
            ),
            'hero_stats_by_time_bracket' => $this->summarizeRoshFlatBuckets(
                (array) ($analysis['hero_stats_by_time_bracket'] ?? []),
                [
                    'heroStatsByTime_1',
                    'heroStatsByTime_2',
                    'heroStatsByTime_3',
                    'heroStatsByTime_4',
                    'heroStatsByTime_5',
                ],
            ),
            'synergy' => $this->summarizeRoshFlatBuckets(
                (array) ($analysis['synergy'] ?? []),
                [
                    'matchUp_Prev_Week_1',
                    'matchUp_Prev_Week_2',
                    'matchUp_Prev_Week_3',
                    'matchUp_Prev_Week_4',
                ],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $match
     * @return array<string, mixed>
     */
    private function buildRoshPlayerAnalysisSummary(array $match): array
    {
        $analysis = (array) data_get($match, 'playerAnalysis', []);

        return [
            'enabled' => (bool) data_get($match, 'considerPlayers', false),
            'source' => (string) ($analysis['source'] ?? 'plus.playerHeroHighlight'),
            'selected_count' => (int) ($analysis['selectedCount'] ?? 0),
            'resolved_count' => (int) ($analysis['resolvedCount'] ?? 0),
            'fallback_count' => (int) ($analysis['fallbackCount'] ?? 0),
            'radiant_total_impact' => round((float) ($analysis['radiantTotalImpact'] ?? 0.0), 2),
            'dire_total_impact' => round((float) ($analysis['direTotalImpact'] ?? 0.0), 2),
            'net_adjustment' => round((float) ($analysis['netAdjustment'] ?? 0.0), 1),
            'request_error' => $analysis['requestError'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $buckets
     * @param  list<string>  $keys
     * @return array<string, array{count:int, sample:array<int, mixed>}>
     */
    private function summarizeRoshFlatBuckets(array $buckets, array $keys): array
    {
        $summary = [];

        foreach ($keys as $key) {
            $rows = array_values(array_filter(
                (array) data_get($buckets, $key, []),
                static fn (mixed $row): bool => is_array($row),
            ));

            $summary[$key] = [
                'count' => count($rows),
                'sample' => array_slice($rows, 0, 3),
            ];
        }

        return $summary;
    }

    private function invertProbability(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round(1 - $value, 4);
    }

    private function clampFloat(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
