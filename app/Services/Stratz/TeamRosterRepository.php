<?php

namespace App\Services\Stratz;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

class TeamRosterRepository
{
    public function __construct(private Filesystem $files) {}

    /**
     * @return list<array{
     *     slug:string,
     *     name:string,
     *     players:list<array<string, mixed>|null>,
     *     updated_at:string
     * }>
     */
    public function all(): array
    {
        $teams = data_get($this->readPayload(), 'teams', []);

        if (! is_array($teams)) {
            return [];
        }

        $normalizedTeams = array_values(array_filter(
            array_map(
                fn (mixed $team): ?array => is_array($team) ? $this->normalizeTeam($team) : null,
                $teams,
            ),
        ));

        usort(
            $normalizedTeams,
            static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']),
        );

        return $normalizedTeams;
    }

    public function existsWithName(string $name, ?string $exceptSlug = null): bool
    {
        $normalizedName = mb_strtolower(trim($name));

        foreach ($this->all() as $team) {
            if ($exceptSlug !== null && $team['slug'] === $exceptSlug) {
                continue;
            }

            if (mb_strtolower($team['name']) === $normalizedName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{name:string, players:list<array<string, mixed>|null>}  $attributes
     * @return array{
     *     slug:string,
     *     name:string,
     *     players:list<array<string, mixed>|null>,
     *     updated_at:string
     * }
     */
    public function create(array $attributes): array
    {
        $teams = $this->all();
        $slug = $this->generateUniqueSlug((string) $attributes['name'], $teams);
        $team = $this->makeTeam($slug, $attributes);

        $teams[] = $team;

        $this->writePayload([
            'teams' => $teams,
        ]);

        return $team;
    }

    /**
     * @param  array{name:string, players:list<array<string, mixed>|null>}  $attributes
     * @return array{
     *     slug:string,
     *     name:string,
     *     players:list<array<string, mixed>|null>,
     *     updated_at:string
     * }
     */
    public function update(string $slug, array $attributes): array
    {
        $teams = $this->all();
        $updatedTeam = null;

        foreach ($teams as $index => $team) {
            if ($team['slug'] !== $slug) {
                continue;
            }

            $updatedTeam = $this->makeTeam($slug, $attributes);
            $teams[$index] = $updatedTeam;
            break;
        }

        if ($updatedTeam === null) {
            throw new RuntimeException("Team roster [{$slug}] was not found.");
        }

        $this->writePayload([
            'teams' => $teams,
        ]);

        return $updatedTeam;
    }

    public function delete(string $slug): void
    {
        $teams = $this->all();
        $filteredTeams = array_values(array_filter(
            $teams,
            static fn (array $team): bool => $team['slug'] !== $slug,
        ));

        if (count($filteredTeams) === count($teams)) {
            throw new RuntimeException("Team roster [{$slug}] was not found.");
        }

        $this->writePayload([
            'teams' => $filteredTeams,
        ]);
    }

    /**
     * @return array{teams:list<array<string, mixed>>}
     */
    private function readPayload(): array
    {
        $path = $this->path();

        if (! $this->files->exists($path)) {
            return [
                'teams' => [],
            ];
        }

        $decoded = json_decode($this->files->get($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The team rosters file contains invalid JSON.');
        }

        return [
            'teams' => is_array($decoded['teams'] ?? null) ? $decoded['teams'] : [],
        ];
    }

    /**
     * @param  array{teams:list<array<string, mixed>>}  $payload
     */
    private function writePayload(array $payload): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! $this->files->isDirectory($directory)) {
            $this->files->ensureDirectoryExists($directory);
        }

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $this->files->put($path, $json.PHP_EOL);
    }

    /**
     * @param  list<array{
     *     slug:string,
     *     name:string,
     *     players:list<array<string, mixed>|null>,
     *     updated_at:string
     * }>  $teams
     */
    private function generateUniqueSlug(string $name, array $teams): string
    {
        $baseSlug = Str::slug($name);
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'team';
        $slug = $baseSlug;
        $suffix = 2;
        $existingSlugs = array_column($teams, 'slug');

        while (in_array($slug, $existingSlugs, true)) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @param  array{name:string, players:list<array<string, mixed>|null>}  $attributes
     * @return array{
     *     slug:string,
     *     name:string,
     *     players:list<array<string, mixed>|null>,
     *     updated_at:string
     * }
     */
    private function makeTeam(string $slug, array $attributes): array
    {
        return [
            'slug' => $slug,
            'name' => trim($attributes['name']),
            'players' => $this->normalizePlayers($attributes['players']),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $team
     * @return array{
     *     slug:string,
     *     name:string,
     *     players:list<array<string, mixed>|null>,
     *     updated_at:string
     * }
     */
    private function normalizeTeam(array $team): array
    {
        return [
            'slug' => is_string($team['slug'] ?? null) && trim($team['slug']) !== ''
                ? trim($team['slug'])
                : $this->generateUniqueSlug((string) ($team['name'] ?? 'team'), []),
            'name' => is_string($team['name'] ?? null) ? trim($team['name']) : 'Unnamed team',
            'players' => $this->normalizePlayers(is_array($team['players'] ?? null) ? $team['players'] : []),
            'updated_at' => is_string($team['updated_at'] ?? null) && trim($team['updated_at']) !== ''
                ? trim($team['updated_at'])
                : now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<mixed>  $players
     * @return list<array<string, mixed>|null>
     */
    private function normalizePlayers(array $players): array
    {
        $players = $this->sortPlayersBySlot($players);

        $normalizedPlayers = array_map(
            function (mixed $player): ?array {
                if (! is_array($player)) {
                    return null;
                }

                $steamAccountId = $player['steam_account_id'] ?? null;
                $name = $player['name'] ?? null;
                $proName = $player['pro_name'] ?? null;
                $teamName = $player['team_name'] ?? null;
                $isAnonymous = $player['is_anonymous'] ?? null;
                $isStratzPublic = $player['is_stratz_public'] ?? null;

                return [
                    'steam_account_id' => is_numeric($steamAccountId) ? (int) $steamAccountId : null,
                    'name' => is_string($name) && trim($name) !== '' ? trim($name) : null,
                    'pro_name' => is_string($proName) && trim($proName) !== '' ? trim($proName) : null,
                    'team_name' => is_string($teamName) && trim($teamName) !== '' ? trim($teamName) : null,
                    'is_anonymous' => is_bool($isAnonymous) ? $isAnonymous : null,
                    'is_stratz_public' => is_bool($isStratzPublic) ? $isStratzPublic : null,
                ];
            },
            array_slice($players, 0, 5),
        );

        return array_pad($normalizedPlayers, 5, null);
    }

    /**
     * @param  array<int|string, mixed>  $players
     * @return list<mixed>
     */
    private function sortPlayersBySlot(array $players): array
    {
        $numericPlayers = [];
        $nonNumericPlayers = [];

        foreach ($players as $slot => $player) {
            if (is_int($slot) || (is_string($slot) && ctype_digit($slot))) {
                $numericPlayers[(int) $slot] = $player;

                continue;
            }

            $nonNumericPlayers[] = $player;
        }

        if ($numericPlayers !== []) {
            ksort($numericPlayers);
        }

        return array_values([
            ...$numericPlayers,
            ...$nonNumericPlayers,
        ]);
    }

    private function path(): string
    {
        return (string) config('services.stratz.team_rosters_path', resource_path('data/stratz-teams.json'));
    }
}
