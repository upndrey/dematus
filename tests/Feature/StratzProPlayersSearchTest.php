<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StratzProPlayersSearchTest extends TestCase
{
    public function test_pro_players_search_uses_liquipedia_prefix_search_for_direct_player_queries(): void
    {
        config()->set('services.liquipedia.endpoint', 'https://liquipedia.net/dota2/api.php');
        Cache::flush();

        Http::fake(function (Request $request) {
            $query = $this->queryParameters($request);

            if (($query['list'] ?? null) === 'prefixsearch') {
                return Http::response([
                    'query' => [
                        'prefixsearch' => [
                            ['ns' => 0, 'title' => 'Timado', 'pageid' => 53889],
                            ['ns' => 0, 'title' => 'TIMS', 'pageid' => 45717],
                            ['ns' => 0, 'title' => 'Timbersaw', 'pageid' => 149553],
                        ],
                    ],
                ]);
            }

            if (($query['prop'] ?? null) === 'revisions') {
                return Http::response([
                    'query' => [
                        'pages' => [
                            '53889' => $this->playerPage(
                                'Timado',
                                97658618,
                                'Timado',
                                'Yamato<!--2024H2-->, TIMADO<!--2026Q1-->',
                                "Enzo Gianoli O'Connor",
                            ),
                            '45717' => $this->nonPlayerPage('TIMS', '{{Infobox player|id=TIMS}}'),
                            '149553' => $this->nonPlayerPage('Timbersaw', '{{Infobox hero|id=Timbersaw}}'),
                        ],
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $response = $this->postJson(route('stratz.pro-players.search'), [
            'query' => '  timado  ',
            'take' => 5,
        ]);

        $response->assertOk();
        $response->assertJsonPath('type', 'pro_players_search');
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.steam_account_id', 97658618);
        $response->assertJsonPath('data.0.name', "Enzo Gianoli O'Connor");
        $response->assertJsonPath('data.0.pro_name', 'Timado');
        $response->assertJsonPath('data.0.aliases.0', 'Yamato');
        $response->assertJsonPath('data.0.team', null);
        $response->assertJsonPath('data.0.last_match_date_time', null);

        Http::assertSent(function (Request $request): bool {
            $query = $this->queryParameters($request);

            return $request->url() === 'https://liquipedia.net/dota2/api.php?action=query&list=prefixsearch&pssearch=++timado++&pslimit=20&psnamespace=0&format=json'
                || (($query['list'] ?? null) === 'prefixsearch')
                || (($query['prop'] ?? null) === 'revisions');
        });

        Http::assertSentCount(2);
    }

    public function test_pro_players_search_falls_back_to_liquipedia_full_search_for_alias_queries(): void
    {
        config()->set('services.liquipedia.endpoint', 'https://liquipedia.net/dota2/api.php');
        Cache::flush();

        Http::fake(function (Request $request) {
            $query = $this->queryParameters($request);

            if (($query['list'] ?? null) === 'prefixsearch') {
                return Http::response([
                    'query' => [
                        'prefixsearch' => [],
                    ],
                ]);
            }

            if (($query['list'] ?? null) === 'search') {
                return Http::response([
                    'query' => [
                        'search' => [
                            ['ns' => 0, 'title' => 'Timado', 'pageid' => 53889],
                            ['ns' => 0, 'title' => 'Shopify Rebellion', 'pageid' => 12345],
                        ],
                    ],
                ]);
            }

            if (($query['prop'] ?? null) === 'revisions') {
                return Http::response([
                    'query' => [
                        'pages' => [
                            '53889' => $this->playerPage(
                                'Timado',
                                97658618,
                                'Timado',
                                'Yamato, TIMADO',
                                "Enzo Gianoli O'Connor",
                            ),
                            '12345' => $this->nonPlayerPage('Shopify Rebellion', '{{Infobox team|id=Shopify Rebellion}}'),
                        ],
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $response = $this->postJson(route('stratz.pro-players.search'), [
            'query' => 'yamato',
            'take' => 5,
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.steam_account_id', 97658618);
        $response->assertJsonPath('data.0.pro_name', 'Timado');

        Http::assertSentCount(3);
    }

    public function test_pro_players_endpoint_returns_an_empty_bulk_list_in_liquipedia_mode(): void
    {
        $response = $this->postJson(route('stratz.pro-players'));

        $response->assertOk();
        $response->assertJsonPath('type', 'pro_players');
        $response->assertJsonCount(0, 'data');
    }

    public function test_pro_players_search_requires_a_meaningful_query(): void
    {
        $response = $this->postJson(route('stratz.pro-players.search'), [
            'query' => ' a ',
        ]);

        $response
            ->assertStatus(422)
            ->assertInvalid(['query']);
    }

    public function test_pro_players_search_returns_a_clear_error_when_liquipedia_fails(): void
    {
        config()->set('services.liquipedia.endpoint', 'https://liquipedia.net/dota2/api.php');
        Cache::flush();

        Http::fake([
            'https://liquipedia.net/dota2/api.php*' => Http::response([], 503),
        ]);

        $response = $this->postJson(route('stratz.pro-players.search'), [
            'query' => 'yatoro',
        ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'error' => 'Liquipedia prefix search request failed with HTTP 503.',
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function queryParameters(Request $request): array
    {
        $query = [];

        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function playerPage(
        string $title,
        int $playerId,
        string $playerName,
        string $aliases,
        string $realName,
    ): array {
        return [
            'pageid' => random_int(1000, 9999),
            'ns' => 0,
            'title' => $title,
            'revisions' => [
                [
                    'slots' => [
                        'main' => [
                            '*' => <<<WIKI
{{Infobox player
|id={$playerName}
|ids={$aliases}
|name={$realName}
|status=Active
|playerid={$playerId}
|team={{PlayerTeamAuto}}
}}
WIKI,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nonPlayerPage(string $title, string $wikitext): array
    {
        return [
            'pageid' => random_int(10000, 19999),
            'ns' => 0,
            'title' => $title,
            'revisions' => [
                [
                    'slots' => [
                        'main' => [
                            '*' => $wikitext,
                        ],
                    ],
                ],
            ],
        ];
    }
}
