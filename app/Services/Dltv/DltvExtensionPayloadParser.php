<?php

namespace App\Services\Dltv;

use App\Enums\Stratz\Hero;
use RuntimeException;

class DltvExtensionPayloadParser
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{radiant_team:string,dire_team:string,radiant_heroes:list<int>,dire_heroes:list<int>,consider_players:bool,radiant_players:list<array<string, mixed>|null>,dire_players:list<array<string, mixed>|null>}
     */
    public function parse(array $payload): array
    {
        $teams = (array) data_get($payload, 'teams', []);
        $radiantTeam = $this->teamPayload($teams, 1);
        $direTeam = $this->teamPayload($teams, 2);
        $radiantPlayers = $this->playersFromTeam($radiantTeam);
        $direPlayers = $this->playersFromTeam($direTeam);

        if (count($radiantPlayers) !== 5 || count($direPlayers) !== 5) {
            throw new RuntimeException('DLTV extension payload must contain exactly 5 players for each team.');
        }

        return [
            'radiant_team' => $this->teamName($payload, $radiantTeam, 1, 'Radiant'),
            'dire_team' => $this->teamName($payload, $direTeam, 2, 'Dire'),
            'radiant_heroes' => $this->heroIds($radiantPlayers, 'Radiant'),
            'dire_heroes' => $this->heroIds($direPlayers, 'Dire'),
            'consider_players' => false,
            'radiant_players' => $this->playerSlots($radiantPlayers),
            'dire_players' => $this->playerSlots($direPlayers),
        ];
    }

    /**
     * @param  array<int, mixed>  $teams
     * @return array<string, mixed>|null
     */
    private function teamPayload(array $teams, int $teamIndex): ?array
    {
        foreach ($teams as $team) {
            if (is_array($team) && (int) data_get($team, 'team_index') === $teamIndex) {
                return $team;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $team
     * @return list<array<string, mixed>>
     */
    private function playersFromTeam(?array $team): array
    {
        if ($team === null) {
            return [];
        }

        $players = array_values(array_filter(
            (array) data_get($team, 'players', []),
            static fn (mixed $player): bool => is_array($player),
        ));

        usort(
            $players,
            static fn (array $left, array $right): int => ((int) ($left['role_id'] ?? 99)) <=> ((int) ($right['role_id'] ?? 99)),
        );

        return $players;
    }

    /**
     * @param  array<string, mixed>|null  $team
     */
    private function teamName(array $payload, ?array $team, int $teamIndex, string $fallback): string
    {
        $teamName = data_get($team, 'team_name')
            ?? data_get($payload, $teamIndex === 1 ? 'match.team_1' : 'match.team_2');

        return is_string($teamName) && trim($teamName) !== '' ? trim($teamName) : $fallback;
    }

    /**
     * @param  list<array<string, mixed>>  $players
     * @return list<int>
     */
    private function heroIds(array $players, string $side): array
    {
        return array_map(
            fn (array $player, int $index): int => $this->heroId($player, "{$side} #".($index + 1)),
            $players,
            array_keys($players),
        );
    }

    /**
     * @param  array<string, mixed>  $player
     */
    private function heroId(array $player, string $slot): int
    {
        $heroName = data_get($player, 'hero_name');

        if (! is_string($heroName) || trim($heroName) === '') {
            throw new RuntimeException("DLTV extension payload is missing hero name for {$slot}.");
        }

        $hero = $this->heroByName($heroName);

        if ($hero === null) {
            throw new RuntimeException("Unsupported DLTV hero name for {$slot}: {$heroName}.");
        }

        return $hero->value;
    }

    private function heroByName(string $name): ?Hero
    {
        $normalized = $this->normalizeHeroName($name);

        foreach (Hero::cases() as $hero) {
            if (
                $this->normalizeHeroName($hero->title()) === $normalized
                || $this->normalizeHeroName($hero->name) === $normalized
            ) {
                return $hero;
            }
        }

        return null;
    }

    private function normalizeHeroName(string $name): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $name));
    }

    /**
     * @param  list<array<string, mixed>>  $players
     * @return list<array<string, mixed>|null>
     */
    private function playerSlots(array $players): array
    {
        return array_map(function (array $player): array {
            $playerName = data_get($player, 'player_name');
            $teamName = data_get($player, 'team_name');

            return [
                'steam_account_id' => null,
                'name' => is_string($playerName) && trim($playerName) !== '' ? trim($playerName) : null,
                'pro_name' => is_string($playerName) && trim($playerName) !== '' ? trim($playerName) : null,
                'is_anonymous' => null,
                'is_stratz_public' => null,
                'team_name' => is_string($teamName) && trim($teamName) !== '' ? trim($teamName) : null,
            ];
        }, $players);
    }
}
