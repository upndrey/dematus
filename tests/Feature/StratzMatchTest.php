<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StratzMatchTest extends TestCase
{
    public function test_match_request_sends_the_required_stratz_user_agent(): void
    {
        config()->set('services.stratz.token', 'test-token');

        Http::fake([
            'https://api.stratz.com/graphql' => Http::response([
                'data' => [
                    'match' => [
                        'id' => 8294471853,
                    ],
                ],
            ]),
        ]);

        $response = $this->postJson(route('stratz.match'), [
            'match_id' => 8294471853,
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'type' => 'match',
                'data' => [
                    'id' => 8294471853,
                ],
            ]);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.stratz.com/graphql'
                && $request->method() === 'POST'
                && $request->hasHeader('User-Agent', 'STRATZ_API')
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['variables']['matchId'] === 8294471853;
        });
    }
}
