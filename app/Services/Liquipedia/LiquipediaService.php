<?php

namespace App\Services\Liquipedia;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LiquipediaService
{
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
        return [];
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

        $cacheKey = $this->searchCacheKey($normalizedQuery, $take);

        /** @var list<array{
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
         * }> $cachedPlayers
         */
        $cachedPlayers = Cache::remember(
            $cacheKey,
            now()->addSeconds(max($this->searchCacheSeconds(), 1)),
            function () use ($normalizedQuery, $query, $take): array {
                $searchLimit = min(max($take * 4, 10), 20);
                $titles = $this->searchPlayerTitles($query, $searchLimit);
                $players = $this->hydratePlayersFromTitles($titles);
                $matches = [];

                foreach ($players as $searchOrder => $player) {
                    $score = $this->scorePlayerMatch($player, $normalizedQuery);

                    if ($score <= 0) {
                        continue;
                    }

                    $matches[] = [
                        'score' => $score,
                        'search_order' => $searchOrder,
                        'player' => $player,
                    ];
                }

                usort($matches, function (array $left, array $right): int {
                    return [
                        $right['score'],
                        $left['search_order'],
                        $this->displayName($left['player']),
                    ] <=> [
                        $left['score'],
                        $right['search_order'],
                        $this->displayName($right['player']),
                    ];
                });

                return array_values(array_map(
                    fn (array $match): array => $this->normalizePlayer($match['player']),
                    array_slice($matches, 0, $take),
                ));
            },
        );

        return $cachedPlayers;
    }

    /**
     * @return list<string>
     */
    private function searchPlayerTitles(string $query, int $limit): array
    {
        $titles = $this->requestPrefixSearchTitles($query, $limit);

        if (count($titles) < min($limit, $this->minimumPrefixMatches())) {
            $titles = $this->mergeTitles(
                $titles,
                $this->requestFullSearchTitles($query, $limit),
                $limit,
            );
        }

        return array_slice($titles, 0, $limit);
    }

    /**
     * @return list<string>
     */
    private function requestPrefixSearchTitles(string $query, int $limit): array
    {
        $payload = $this->request(
            [
                'action' => 'query',
                'list' => 'prefixsearch',
                'pssearch' => $query,
                'pslimit' => $limit,
                'psnamespace' => 0,
                'format' => 'json',
            ],
            'Liquipedia prefix search request failed with HTTP ',
        );

        $results = data_get($payload, 'query.prefixsearch');

        if (! is_array($results)) {
            return [];
        }

        $titles = [];

        foreach ($results as $result) {
            $title = $this->filterTitle(is_array($result) ? data_get($result, 'title') : null);

            if ($title === null) {
                continue;
            }

            $titles[] = $title;
        }

        return array_values(array_unique($titles));
    }

    /**
     * @return list<string>
     */
    private function requestFullSearchTitles(string $query, int $limit): array
    {
        $payload = $this->request(
            [
                'action' => 'query',
                'list' => 'search',
                'srsearch' => $query,
                'srlimit' => $limit,
                'srnamespace' => 0,
                'format' => 'json',
            ],
            'Liquipedia search request failed with HTTP ',
        );

        $results = data_get($payload, 'query.search');

        if (! is_array($results)) {
            return [];
        }

        $titles = [];

        foreach ($results as $result) {
            $title = $this->filterTitle(is_array($result) ? data_get($result, 'title') : null);

            if ($title === null) {
                continue;
            }

            $titles[] = $title;
        }

        return array_values(array_unique($titles));
    }

    /**
     * @param  list<string>  $existingTitles
     * @param  list<string>  $additionalTitles
     * @return list<string>
     */
    private function mergeTitles(array $existingTitles, array $additionalTitles, int $limit): array
    {
        $titles = $existingTitles;

        foreach ($additionalTitles as $title) {
            if (in_array($title, $titles, true)) {
                continue;
            }

            $titles[] = $title;

            if (count($titles) >= $limit) {
                break;
            }
        }

        return $titles;
    }

    /**
     * @param  list<string>  $titles
     * @return list<array{
     *     page_title:string,
     *     steam_account_id:int,
     *     name:string,
     *     pro_name:string,
     *     aliases:list<string>
     * }>
     */
    private function hydratePlayersFromTitles(array $titles): array
    {
        if ($titles === []) {
            return [];
        }

        $resolvedPlayers = [];
        $missingTitles = [];

        foreach ($titles as $title) {
            $cacheKey = $this->pageCacheKey($title);

            if (Cache::has($cacheKey)) {
                $cachedPlayer = Cache::get($cacheKey);

                if (is_array($cachedPlayer)) {
                    $resolvedPlayers[$title] = $cachedPlayer;
                }

                continue;
            }

            $missingTitles[] = $title;
        }

        if ($missingTitles !== []) {
            foreach ($this->requestPlayerPages($missingTitles) as $title => $player) {
                Cache::put(
                    $this->pageCacheKey($title),
                    $player ?: false,
                    now()->addSeconds(max($this->pageCacheSeconds(), 1)),
                );

                if (is_array($player)) {
                    $resolvedPlayers[$title] = $player;
                }
            }
        }

        $players = [];

        foreach ($titles as $title) {
            if (isset($resolvedPlayers[$title]) && is_array($resolvedPlayers[$title])) {
                $players[] = $resolvedPlayers[$title];
            }
        }

        return $players;
    }

    /**
     * @param  list<string>  $titles
     * @return array<string, array{
     *     page_title:string,
     *     steam_account_id:int,
     *     name:string,
     *     pro_name:string,
     *     aliases:list<string>
     * }|false>
     */
    private function requestPlayerPages(array $titles): array
    {
        $payload = $this->request(
            [
                'action' => 'query',
                'prop' => 'revisions',
                'titles' => implode('|', $titles),
                'rvprop' => 'content',
                'rvslots' => 'main',
                'redirects' => 1,
                'format' => 'json',
            ],
            'Liquipedia player details request failed with HTTP ',
        );

        $pages = data_get($payload, 'query.pages');

        if (! is_array($pages)) {
            return [];
        }

        $playersByTitle = [];

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }

            $title = $this->trimmedString(data_get($page, 'title'));

            if ($title === '') {
                continue;
            }

            $playersByTitle[$title] = $this->parsePlayerPage($page) ?: false;
        }

        foreach ($titles as $title) {
            if (! array_key_exists($title, $playersByTitle)) {
                $playersByTitle[$title] = false;
            }
        }

        return $playersByTitle;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array{
     *     page_title:string,
     *     steam_account_id:int,
     *     name:string,
     *     pro_name:string,
     *     aliases:list<string>
     * }|null
     */
    private function parsePlayerPage(array $page): ?array
    {
        $title = $this->trimmedString(data_get($page, 'title'));
        $mainSlot = data_get($page, 'revisions.0.slots.main');
        $wikitext = is_array($mainSlot) ? ($mainSlot['*'] ?? null) : null;

        if ($title === '' || ! is_string($wikitext) || ! str_contains($wikitext, '{{Infobox player')) {
            return null;
        }

        $playerId = $this->extractPlayerId($wikitext);

        if ($playerId === null) {
            return null;
        }

        $status = $this->normalizeQuery($this->extractField($wikitext, 'status'));

        if ($status !== '' && $status !== 'active') {
            return null;
        }

        $proName = $this->cleanWikitextValue($this->extractField($wikitext, 'id'));
        $realName = $this->cleanWikitextValue($this->extractField($wikitext, 'name'));
        $aliases = $this->extractAliases($wikitext, $proName, $title);

        if ($proName === '') {
            $proName = $title;
        }

        return [
            'page_title' => $title,
            'steam_account_id' => $playerId,
            'name' => $realName !== '' ? $realName : $proName,
            'pro_name' => $proName,
            'aliases' => $aliases,
        ];
    }

    /**
     * @param  array{
     *     page_title:string,
     *     steam_account_id:int,
     *     name:string,
     *     pro_name:string,
     *     aliases:list<string>
     * }  $player
     */
    private function scorePlayerMatch(array $player, string $query): int
    {
        $score = 0;
        $score += $this->scoreTextMatch($this->normalizeQuery($player['pro_name']), $query, 1200, 900, 650, 420);
        $score += $this->scoreTextMatch($this->normalizeQuery($player['page_title']), $query, 1050, 820, 600, 380);

        foreach ($player['aliases'] as $alias) {
            $score += $this->scoreTextMatch($this->normalizeQuery($alias), $query, 900, 720, 520, 260);
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
     * @param  array{
     *     page_title:string,
     *     steam_account_id:int,
     *     name:string,
     *     pro_name:string,
     *     aliases:list<string>
     * }  $player
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
    private function normalizePlayer(array $player): array
    {
        return [
            'steam_account_id' => $player['steam_account_id'],
            'name' => $player['name'],
            'is_anonymous' => false,
            'is_stratz_public' => false,
            'last_match_date_time' => null,
            'season_rank' => null,
            'season_leaderboard_rank' => null,
            'pro_name' => $player['pro_name'],
            'aliases' => $player['aliases'],
            'team' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function request(array $query, string $errorPrefix): array
    {
        $response = Http::acceptJson()
            ->withHeaders([
                'User-Agent' => $this->userAgent(),
                'Accept-Encoding' => 'gzip',
            ])
            ->connectTimeout(5)
            ->timeout($this->timeout())
            ->retry(2, 250, throw: false)
            ->get($this->endpoint(), $query);

        if ($response->failed()) {
            throw new RuntimeException($errorPrefix.$response->status().'.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Invalid Liquipedia response format.');
        }

        $error = data_get($payload, 'error.info');

        if (is_string($error) && $error !== '') {
            throw new RuntimeException('Liquipedia API error: '.$error);
        }

        return $payload;
    }

    private function extractPlayerId(string $wikitext): ?int
    {
        if (preg_match('/^\|\s*playerid\s*=\s*(\d+)/mi', $wikitext, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function extractField(string $wikitext, string $field): string
    {
        if (preg_match('/^\|\s*'.preg_quote($field, '/').'\s*=\s*(.+)$/mi', $wikitext, $matches) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }

    /**
     * @return list<string>
     */
    private function extractAliases(string $wikitext, string $proName, string $title): array
    {
        $aliases = [];
        $rawAliases = $this->extractField($wikitext, 'ids');

        if ($rawAliases !== '') {
            $cleanAliases = preg_replace('/<!--.*?-->/s', '', $rawAliases) ?? $rawAliases;

            foreach (explode(',', $cleanAliases) as $alias) {
                $cleanAlias = $this->cleanWikitextValue($alias);

                if ($cleanAlias === '') {
                    continue;
                }

                $aliases[] = $cleanAlias;
            }
        }

        $uniqueAliases = [];
        $seenAliases = [
            $this->normalizeQuery($proName) => true,
            $this->normalizeQuery($title) => true,
        ];

        foreach ($aliases as $alias) {
            $normalizedAlias = $this->normalizeQuery($alias);

            if ($normalizedAlias === '' || isset($seenAliases[$normalizedAlias])) {
                continue;
            }

            $seenAliases[$normalizedAlias] = true;
            $uniqueAliases[] = $alias;
        }

        return $uniqueAliases;
    }

    private function cleanWikitextValue(string $value): string
    {
        $cleanValue = preg_replace('/<!--.*?-->/s', '', $value) ?? $value;
        $cleanValue = preg_replace('/<ref[^>]*>.*?<\/ref>/si', ' ', $cleanValue) ?? $cleanValue;
        $cleanValue = preg_replace('/<[^>]+>/', ' ', $cleanValue) ?? $cleanValue;
        $cleanValue = preg_replace('/\[\[(?:[^|\]]*\|)?([^\]]+)\]\]/', '$1', $cleanValue) ?? $cleanValue;
        $cleanValue = preg_replace('/\{\{[^{}]*\}\}/', ' ', $cleanValue) ?? $cleanValue;
        $cleanValue = html_entity_decode($cleanValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cleanValue = preg_replace('/\s+/', ' ', $cleanValue) ?? $cleanValue;

        return trim($cleanValue, " \t\n\r\0\x0B,");
    }

    private function displayName(array $player): string
    {
        return $player['pro_name'];
    }

    private function normalizeQuery(string $value): string
    {
        $normalizedValue = function_exists('mb_strtolower')
            ? mb_strtolower($value)
            : strtolower($value);

        $normalizedValue = preg_replace('/[^\p{L}\p{N}]+/u', ' ', trim($normalizedValue)) ?? $normalizedValue;
        $normalizedValue = preg_replace('/\s+/', ' ', $normalizedValue) ?? $normalizedValue;

        return trim($normalizedValue);
    }

    private function filterTitle(mixed $title): ?string
    {
        $cleanTitle = $this->trimmedString($title);

        if ($cleanTitle === '' || str_contains($cleanTitle, '/')) {
            return null;
        }

        return $cleanTitle;
    }

    private function trimmedString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function searchCacheKey(string $normalizedQuery, int $take): string
    {
        return 'liquipedia.pro_player_search.'.sha1($normalizedQuery.'|'.$take);
    }

    private function pageCacheKey(string $title): string
    {
        return 'liquipedia.pro_player_page.'.sha1($this->normalizeQuery($title));
    }

    private function endpoint(): string
    {
        return (string) config('services.liquipedia.endpoint', 'https://liquipedia.net/dota2/api.php');
    }

    private function timeout(): int
    {
        return max((int) config('services.liquipedia.timeout', 15), 1);
    }

    private function searchCacheSeconds(): int
    {
        return max((int) config('services.liquipedia.search_cache_seconds', 21600), 1);
    }

    private function pageCacheSeconds(): int
    {
        return max((int) config('services.liquipedia.page_cache_seconds', 86400), 1);
    }

    private function userAgent(): string
    {
        return (string) config('services.liquipedia.user_agent', 'dematus-liquipedia/1.0 (contact: local-tool)');
    }

    private function minimumPrefixMatches(): int
    {
        return 3;
    }
}
