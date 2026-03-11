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

    private function mapGameModeToId(string $gameMode): int
    {
        if (isset(self::GAME_MODE_IDS[$gameMode])) {
            return self::GAME_MODE_IDS[$gameMode];
        }

        throw new \RuntimeException("Unsupported STRATZ game mode '{$gameMode}'.");
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
