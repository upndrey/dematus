<?php

namespace App\Services\OpenDota;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenDotaService
{
    private const PRO_PLAYERS_CACHE_KEY = 'opendota.pro_players';

    private const USER_AGENT = 'dematus-opendota/1.0';

    /**
     * @return list<array{
     *     steam_account_id:int,
     *     name:string,
     *     is_anonymous:bool,
     *     is_stratz_public:bool,
     *     last_match_date_time:int|null,
     *     season_rank:int|null,
     *     season_leaderboard_rank:int|null,
     *     pro_name:string|null,
     *     aliases:list<string>,
     *     team:array{id:int, name:string}|null
     * }>
     */
    public function getProPlayers(): array
    {
        $players = $this->fetchCachedProPlayers();

        usort($players, function (array $left, array $right): int {
            return [
                $this->displayName($left),
                $this->teamName($left),
                $this->lastMatchTimestamp($right),
            ] <=> [
                $this->displayName($right),
                $this->teamName($right),
                $this->lastMatchTimestamp($left),
            ];
        });

        return array_values(array_map(
            fn (array $player): array => $this->normalizeProPlayer($player),
            $players,
        ));
    }

    /**
     * @return list<array{
     *     steam_account_id:int,
     *     name:string,
     *     is_anonymous:bool,
     *     is_stratz_public:bool,
     *     last_match_date_time:int|null,
     *     season_rank:int|null,
     *     season_leaderboard_rank:int|null,
     *     pro_name:string|null,
     *     aliases:list<string>,
     *     team:array{id:int, name:string}|null
     * }>
     */
    public function searchProPlayers(string $query, int $take = 5): array
    {
        $normalizedQuery = $this->normalizeQuery($query);

        if ($normalizedQuery === '') {
            return [];
        }

        $matches = [];

        foreach ($this->fetchCachedProPlayers() as $player) {
            $score = $this->scorePlayerMatch($player, $normalizedQuery);

            if ($score <= 0) {
                continue;
            }

            $matches[] = [
                'score' => $score,
                'last_match_timestamp' => $this->lastMatchTimestamp($player),
                'player' => $player,
            ];
        }

        usort($matches, function (array $left, array $right): int {
            return [
                $right['score'],
                $right['last_match_timestamp'],
                $this->displayName($left['player']),
            ] <=> [
                $left['score'],
                $left['last_match_timestamp'],
                $this->displayName($right['player']),
            ];
        });

        return array_values(array_map(
            fn (array $match): array => $this->normalizeProPlayer($match['player']),
            array_slice($matches, 0, $take),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchCachedProPlayers(): array
    {
        return Cache::remember(
            self::PRO_PLAYERS_CACHE_KEY,
            now()->addSeconds(max($this->cacheSeconds(), 1)),
            fn (): array => $this->requestProPlayers(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requestProPlayers(): array
    {
        $response = Http::acceptJson()
            ->withHeaders([
                'User-Agent' => self::USER_AGENT,
            ])
            ->timeout($this->timeout())
            ->retry(2, 200, throw: false)
            ->get($this->proPlayersEndpoint());

        if ($response->failed()) {
            throw new RuntimeException(
                'OpenDota pro players request failed with HTTP '.$response->status().'.',
            );
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Invalid OpenDota response format.');
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $player): ?array => is_array($player) ? $player : null,
                $payload,
            ),
            fn (?array $player): bool => is_array($player) && $this->isSupportedProPlayer($player),
        ));
    }

    /**
     * @param  array<string, mixed>  $player
     */
    private function isSupportedProPlayer(array $player): bool
    {
        if (! is_numeric(data_get($player, 'account_id'))) {
            return false;
        }

        return $this->displayName($player) !== ''
            && ((bool) data_get($player, 'is_pro') || (bool) data_get($player, 'is_locked'));
    }

    /**
     * @param  array<string, mixed>  $player
     */
    private function scorePlayerMatch(array $player, string $query): int
    {
        $displayName = $this->normalizeQuery($this->displayName($player));
        $personaName = $this->normalizeQuery($this->personaName($player));
        $teamName = $this->normalizeQuery($this->teamName($player));

        $textScore = 0;
        $textScore += $this->scoreTextMatch($displayName, $query, 1200, 900, 650, 420);
        $textScore += $this->scoreTextMatch($personaName, $query, 500, 360, 240, 120);
        $textScore += $this->scoreTextMatch($teamName, $query, 120, 80, 40, 20);

        if ($textScore <= 0) {
            return 0;
        }

        $score = $textScore;

        if ((bool) data_get($player, 'is_pro')) {
            $score += 120;
        }

        if ((bool) data_get($player, 'is_locked')) {
            $score += 40;
        }

        return $score;
    }

    private function scoreTextMatch(
        string $haystack,
        string $query,
        int $exactScore,
        int $prefixScore,
        int $wordPrefixScore,
        int $containsScore,
    ): int {
        if ($haystack === '' || $query === '') {
            return 0;
        }

        if ($haystack === $query) {
            return $exactScore;
        }

        if (str_starts_with($haystack, $query)) {
            return $prefixScore;
        }

        foreach (preg_split('/\s+/', $haystack) ?: [] as $token) {
            if ($token !== '' && str_starts_with($token, $query)) {
                return $wordPrefixScore;
            }
        }

        if (str_contains($haystack, $query)) {
            return $containsScore;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $player
     * @return array{
     *     steam_account_id:int,
     *     name:string,
     *     is_anonymous:bool,
     *     is_stratz_public:bool,
     *     last_match_date_time:int|null,
     *     season_rank:int|null,
     *     season_leaderboard_rank:int|null,
     *     pro_name:string|null,
     *     aliases:list<string>,
     *     team:array{id:int, name:string}|null
     * }
     */
    private function normalizeProPlayer(array $player): array
    {
        $displayName = $this->displayName($player);
        $personaName = $this->personaName($player);
        $teamId = data_get($player, 'team_id');
        $teamName = $this->teamName($player);

        return [
            'steam_account_id' => (int) data_get($player, 'account_id'),
            'name' => $personaName !== '' ? $personaName : $displayName,
            'is_anonymous' => false,
            'is_stratz_public' => false,
            'last_match_date_time' => $this->normalizeDateTime(data_get($player, 'last_match_time')),
            'season_rank' => null,
            'season_leaderboard_rank' => null,
            'pro_name' => $displayName !== '' ? $displayName : null,
            'aliases' => [],
            'team' => is_numeric($teamId) && $teamName !== ''
                ? [
                    'id' => (int) $teamId,
                    'name' => $teamName,
                ]
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $player
     */
    private function displayName(array $player): string
    {
        return $this->trimmedString(data_get($player, 'name'));
    }

    /**
     * @param  array<string, mixed>  $player
     */
    private function personaName(array $player): string
    {
        return $this->trimmedString(data_get($player, 'personaname'));
    }

    /**
     * @param  array<string, mixed>  $player
     */
    private function teamName(array $player): string
    {
        return $this->trimmedString(data_get($player, 'team_name'));
    }

    private function normalizeQuery(string $value): string
    {
        $normalized = trim($value);
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($normalized)
            : strtolower($normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
    }

    private function normalizeDateTime(mixed $value): ?int
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @param  array<string, mixed>  $player
     */
    private function lastMatchTimestamp(array $player): int
    {
        return $this->normalizeDateTime(data_get($player, 'last_match_time')) ?? 0;
    }

    private function trimmedString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function proPlayersEndpoint(): string
    {
        return (string) config('services.opendota.pro_players_endpoint');
    }

    private function timeout(): int
    {
        return (int) config('services.opendota.timeout', 10);
    }

    private function cacheSeconds(): int
    {
        return (int) config('services.opendota.pro_players_cache_seconds', 900);
    }
}
