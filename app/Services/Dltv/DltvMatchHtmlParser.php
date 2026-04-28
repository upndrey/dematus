<?php

namespace App\Services\Dltv;

use RuntimeException;

class DltvMatchHtmlParser
{
    /**
     * @return array{
     *     radiant_team:string,
     *     dire_team:string,
     *     radiant_heroes:list<int>,
     *     dire_heroes:list<int>
     * }
     */
    public function parse(string $html): array
    {
        $seriesItem = $this->extractSeriesItem($html);

        foreach ($this->draftCandidates($html, $seriesItem) as $candidate) {
            $payload = $this->normalizeDraftCandidate($candidate);

            if ($payload !== null) {
                return $payload;
            }
        }

        throw new RuntimeException('DLTV HTML does not contain a complete draft with 5 Radiant and 5 Dire picked heroes.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractSeriesItem(string $html): ?array
    {
        $json = $this->extractJsonAfterMarker($html, 'let series_item =');

        if ($json === null) {
            return null;
        }

        return $this->decodeJsonObject($json);
    }

    /**
     * @param  array<string, mixed>|null  $seriesItem
     * @return list<array<string, mixed>>
     */
    private function draftCandidates(string $html, ?array $seriesItem): array
    {
        $candidates = [];

        if ($seriesItem !== null) {
            foreach ((array) data_get($seriesItem, 'maps', []) as $map) {
                if (! is_array($map)) {
                    continue;
                }

                $candidates[] = [
                    'radiant_team' => $this->resolveTeamName($seriesItem, $map, true),
                    'dire_team' => $this->resolveTeamName($seriesItem, $map, false),
                    'radiant_picks' => data_get($map, 'radiant_picks'),
                    'dire_picks' => data_get($map, 'dire_picks'),
                ];
            }
        }

        array_push($candidates, ...$this->extractLivePayloadCandidates($html));
        array_push($candidates, ...$this->extractRenderedPickCandidates($html, $seriesItem));

        return $candidates;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractLivePayloadCandidates(string $html): array
    {
        $candidates = [];

        foreach ($this->extractJsonObjectsNearMarker($html, '"db"') as $payload) {
            $firstTeam = data_get($payload, 'db.first_team');
            $secondTeam = data_get($payload, 'db.second_team');

            if (! is_array($firstTeam) || ! is_array($secondTeam)) {
                continue;
            }

            $firstTeamIsRadiant = (bool) data_get($firstTeam, 'is_radiant');
            $radiantTeam = $firstTeamIsRadiant ? $firstTeam : $secondTeam;
            $direTeam = $firstTeamIsRadiant ? $secondTeam : $firstTeam;

            $candidates[] = [
                'radiant_team' => (string) data_get($radiantTeam, 'title', 'Radiant'),
                'dire_team' => (string) data_get($direTeam, 'title', 'Dire'),
                'radiant_picks' => data_get($radiantTeam, 'picks', []),
                'dire_picks' => data_get($direTeam, 'picks', []),
            ];

            $fastPicks = data_get($payload, 'fast_picks');

            if (is_array($fastPicks)) {
                $candidates[] = [
                    'radiant_team' => (string) data_get($radiantTeam, 'title', 'Radiant'),
                    'dire_team' => (string) data_get($direTeam, 'title', 'Dire'),
                    'radiant_picks' => $firstTeamIsRadiant
                        ? data_get($fastPicks, 'first_team', [])
                        : data_get($fastPicks, 'second_team', []),
                    'dire_picks' => $firstTeamIsRadiant
                        ? data_get($fastPicks, 'second_team', [])
                        : data_get($fastPicks, 'first_team', []),
                ];
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>|null  $seriesItem
     * @return list<array<string, mixed>>
     */
    private function extractRenderedPickCandidates(string $html, ?array $seriesItem): array
    {
        $playerRolesBySlug = $this->extractPlayerRolesBySlug($seriesItem);
        $radiantPicks = $this->extractRenderedSidePicks($html, 'radiant', $playerRolesBySlug);
        $direPicks = $this->extractRenderedSidePicks($html, 'dire', $playerRolesBySlug);

        if (count($radiantPicks) !== 5 || count($direPicks) !== 5) {
            return [];
        }

        $map = (array) data_get($seriesItem, 'maps.0', []);

        return [[
            'radiant_team' => is_array($seriesItem) ? $this->resolveTeamName($seriesItem, $map, true) : 'Radiant',
            'dire_team' => is_array($seriesItem) ? $this->resolveTeamName($seriesItem, $map, false) : 'Dire',
            'radiant_picks' => $radiantPicks,
            'dire_picks' => $direPicks,
        ]];
    }

    /**
     * @param  array<string, int>  $playerRolesBySlug
     * @return list<array{hero_id:int, role:int}>
     */
    private function extractRenderedSidePicks(string $html, string $side, array $playerRolesBySlug): array
    {
        if (! preg_match(
            '/picks__new-picks__picks\s+'.preg_quote($side, '/').'.*?(?=<div class="picks__new-picks__picks|\z)/is',
            $html,
            $sideMatches,
        )) {
            return [];
        }

        preg_match_all(
            '/<div\s+class="pick\b[^"]*\bplayer\b[^"]*"[^>]*data-hero-id="(?P<hero_id>\d+)"[^>]*>(?P<body>.*?)(?=<div\s+class="pick\b[^"]*\bplayer\b|<div class="bans"|\z)/is',
            $sideMatches[0],
            $pickMatches,
            PREG_SET_ORDER,
        );

        return array_values(array_map(
            static fn (array $match, int $index): array => [
                'hero_id' => (int) $match['hero_id'],
                'role' => self::resolveRenderedPickRole((string) ($match['body'] ?? ''), $playerRolesBySlug) ?? $index + 1,
            ],
            $pickMatches,
            array_keys($pickMatches),
        ));
    }

    /**
     * @param  array<string, mixed>|null  $seriesItem
     * @return array<string, int>
     */
    private function extractPlayerRolesBySlug(?array $seriesItem): array
    {
        $rolesBySlug = [];

        foreach ((array) data_get($seriesItem, 'series_players', []) as $seriesPlayer) {
            if (! is_array($seriesPlayer)) {
                continue;
            }

            $slug = data_get($seriesPlayer, 'player.slug') ?? data_get($seriesPlayer, 'slug');
            $role = data_get($seriesPlayer, 'role') ?? data_get($seriesPlayer, 'player.role');

            if (is_string($slug) && $slug !== '' && is_numeric($role)) {
                $rolesBySlug[$slug] = max(1, min(5, (int) $role));
            }
        }

        return $rolesBySlug;
    }

    /**
     * @param  array<string, int>  $playerRolesBySlug
     */
    private static function resolveRenderedPickRole(string $pickHtml, array $playerRolesBySlug): ?int
    {
        if (! preg_match('/\/players\/(?P<slug>[^?"\/#]+)/i', $pickHtml, $matches)) {
            return null;
        }

        return $playerRolesBySlug[$matches['slug']] ?? null;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array{
     *     radiant_team:string,
     *     dire_team:string,
     *     radiant_heroes:list<int>,
     *     dire_heroes:list<int>
     * }|null
     */
    private function normalizeDraftCandidate(array $candidate): ?array
    {
        $radiantPicks = $this->normalizePicks((array) ($candidate['radiant_picks'] ?? []));
        $direPicks = $this->normalizePicks((array) ($candidate['dire_picks'] ?? []));

        if (count($radiantPicks) !== 5 || count($direPicks) !== 5) {
            return null;
        }

        return [
            'radiant_team' => $this->filledTeamName($candidate['radiant_team'] ?? null, 'Radiant'),
            'dire_team' => $this->filledTeamName($candidate['dire_team'] ?? null, 'Dire'),
            'radiant_heroes' => array_column($radiantPicks, 'hero_id'),
            'dire_heroes' => array_column($direPicks, 'hero_id'),
        ];
    }

    /**
     * @param  list<mixed>  $picks
     * @return list<array{hero_id:int, role:int}>
     */
    private function normalizePicks(array $picks): array
    {
        $normalized = [];

        foreach (array_values($picks) as $index => $pick) {
            if (! is_array($pick)) {
                continue;
            }

            $heroId = data_get($pick, 'hero_id')
                ?? data_get($pick, 'steam_id')
                ?? data_get($pick, 'hero.steam_id')
                ?? data_get($pick, 'hero.hero_id')
                ?? data_get($pick, 'data.hero_id');

            if (! is_numeric($heroId)) {
                continue;
            }

            $role = data_get($pick, 'role')
                ?? data_get($pick, 'position')
                ?? data_get($pick, 'position_id')
                ?? data_get($pick, 'player.role')
                ?? data_get($pick, 'data.role')
                ?? $index + 1;

            $normalized[] = [
                'hero_id' => (int) $heroId,
                'role' => is_numeric($role) ? max(1, min(5, (int) $role)) : $index + 1,
            ];
        }

        usort(
            $normalized,
            static fn (array $left, array $right): int => $left['role'] <=> $right['role'],
        );

        return array_values($normalized);
    }

    /**
     * @param  array<string, mixed>  $seriesItem
     * @param  array<string, mixed>  $map
     */
    private function resolveTeamName(array $seriesItem, array $map, bool $isRadiant): string
    {
        $sideKey = $isRadiant ? 'radiant_team' : 'dire_team';
        $sideIdKey = $isRadiant ? 'radiant_team_id' : 'dire_team_id';
        $fallbackKey = $isRadiant ? 'first_team' : 'second_team';
        $teamName = data_get($map, $sideKey.'.title');

        if (is_string($teamName) && $teamName !== '') {
            return $teamName;
        }

        $teamId = data_get($map, $sideIdKey);

        foreach (['first_team', 'second_team'] as $teamKey) {
            if ($teamId !== null && (int) data_get($seriesItem, $teamKey.'.id') === (int) $teamId) {
                return (string) data_get($seriesItem, $teamKey.'.title', $isRadiant ? 'Radiant' : 'Dire');
            }
        }

        return (string) data_get($seriesItem, $fallbackKey.'.title', $isRadiant ? 'Radiant' : 'Dire');
    }

    private function filledTeamName(mixed $value, string $fallback): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }

    private function extractJsonAfterMarker(string $html, string $marker): ?string
    {
        $markerPosition = strpos($html, $marker);

        if ($markerPosition === false) {
            return null;
        }

        $start = strpos($html, '{', $markerPosition);

        return $start === false ? null : $this->extractBalancedJsonObject($html, $start);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractJsonObjectsNearMarker(string $html, string $marker): array
    {
        $objects = [];
        $offset = 0;

        while (($markerPosition = strpos($html, $marker, $offset)) !== false) {
            $offset = $markerPosition + strlen($marker);
            $windowStart = max(0, $markerPosition - 5000);
            $prefix = substr($html, $windowStart, $markerPosition - $windowStart);
            $candidateStarts = [];
            $searchOffset = 0;

            while (($relativeStart = strpos($prefix, '{', $searchOffset)) !== false) {
                $candidateStarts[] = $windowStart + $relativeStart;
                $searchOffset = $relativeStart + 1;
            }

            foreach (array_reverse($candidateStarts) as $start) {
                $json = $this->extractBalancedJsonObject($html, $start);

                if ($json === null || ! str_contains($json, '"first_team"') || ! str_contains($json, '"second_team"')) {
                    continue;
                }

                $object = $this->decodeJsonObject($json);

                if (is_array(data_get($object, 'db.first_team'))) {
                    $objects[] = $object;
                    break;
                }
            }
        }

        return $objects;
    }

    private function extractBalancedJsonObject(string $html, int $start): ?string
    {
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($html);

        for ($index = $start; $index < $length; $index++) {
            $char = $html[$index];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($html, $start, $index - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
