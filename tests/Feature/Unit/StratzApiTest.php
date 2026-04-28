<?php

namespace Tests\Feature\Unit;

use App\Services\Stratz\Api;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class StratzApiTest extends TestCase
{
    public function test_it_returns_data_from_stratz_on_successful_response(): void
    {
        config()->set('services.stratz.token', 'test-token');
        config()->set('services.stratz.endpoint', 'https://api.stratz.com/graphql');

        Http::fake([
            'https://api.stratz.com/graphql' => Http::response([
                'data' => [
                    'stratz' => [
                        'search' => [
                            'proPlayers' => [],
                        ],
                    ],
                ],
            ]),
        ]);

        $api = app(Api::class);

        $data = $api->query('query SearchProPlayers { stratz { search(request: { query: "mi" }) { proPlayers { id } } } }');

        $this->assertSame([], data_get($data, 'stratz.search.proPlayers'));
    }

    public function test_it_returns_friendly_message_for_stratz_ip_binding_403_error(): void
    {
        config()->set('services.stratz.token', 'test-token');
        config()->set('services.stratz.endpoint', 'https://api.stratz.com/graphql');

        Http::fake([
            'https://api.stratz.com/graphql' => Http::response(
                'You cannot use different IP Addresses when using the API.',
                403,
            ),
        ]);

        $api = app(Api::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'STRATZ token is tied to another public IP address. Use this token only from one IP, disable VPN/proxy switching, or create a separate token for this environment.',
        );

        $api->query('query SearchProPlayers { stratz { search(request: { query: "mi" }) { proPlayers { id } } } }');
    }

    public function test_it_can_return_partial_graphql_payload_without_throwing(): void
    {
        config()->set('services.stratz.token', 'test-token');
        config()->set('services.stratz.endpoint', 'https://api.stratz.com/graphql');

        Http::fake([
            'https://api.stratz.com/graphql' => Http::response([
                'data' => [
                    'plus' => [
                        'player_0' => [
                            'matchCount' => 12,
                        ],
                    ],
                ],
                'errors' => [
                    [
                        'message' => 'Player Id is missing or anonymous.',
                        'path' => ['plus', 'player_5'],
                    ],
                ],
            ]),
        ]);

        $api = app(Api::class);

        $payload = $api->queryAllowPartial('query PlayerHeroHighlights { plus { player_0: playerHeroHighlight { matchCount } } }');

        $this->assertSame(12, data_get($payload, 'data.plus.player_0.matchCount'));
        $this->assertSame('Player Id is missing or anonymous.', data_get($payload, 'errors.0.message'));
        $this->assertSame(['plus', 'player_5'], data_get($payload, 'errors.0.path'));
    }
}
