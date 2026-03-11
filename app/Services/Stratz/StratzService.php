<?php

namespace App\Services\Stratz;

class StratzService
{
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
}
