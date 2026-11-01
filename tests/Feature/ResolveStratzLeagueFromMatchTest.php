<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResolveStratzLeagueFromMatchTest extends TestCase
{
    public function test_it_uses_opendota_when_stratz_match_has_no_league(): void
    {
        config()->set('services.stratz.token', 'test-token');
        config()->set('services.stratz.endpoint', 'https://api.stratz.com/graphql');

        Http::fake(function (Request $request) {
            if ($request->url() === 'https://api.stratz.com/graphql') {
                return Http::response(['data' => ['match' => ['league' => null]]]);
            }

            if ($request->url() === 'https://api.opendota.com/api/matches/8902338515') {
                return Http::response(['leagueid' => 19785]);
            }

            if ($request->url() === 'https://api.opendota.com/api/leagues/19785') {
                return Http::response(['name' => 'Esports World Cup 2026']);
            }

            return Http::response([], 404);
        });

        $response = $this->authenticateStatically()->postJson(route('stratz.import-batches.resolve-league'), [
            'match_id' => 8902338515,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.id', 19785);
        $response->assertJsonPath('data.displayName', 'Esports World Cup 2026');

        Http::assertSentCount(3);
    }

    public function test_it_uses_opendota_league_matches_when_stratz_returns_an_empty_list(): void
    {
        config()->set('services.stratz.token', 'test-token');
        config()->set('services.stratz.endpoint', 'https://api.stratz.com/graphql');

        Http::fake(function (Request $request) {
            if ($request->url() === 'https://api.stratz.com/graphql') {
                return Http::response(['data' => ['league' => ['matches' => []]]]);
            }

            if ($request->url() === 'https://api.opendota.com/api/leagues/19785/matches') {
                return Http::response([
                    ['match_id' => 8902431810],
                    ['match_id' => 8902338515],
                ]);
            }

            return Http::response([], 404);
        });

        $response = $this->authenticateStatically()->postJson(route('stratz.import-batches.league-preview'), [
            'league_id' => 19785,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.source', 'opendota');
        $response->assertJsonPath('data.match_ids', [8902431810, 8902338515]);

        Http::assertSentCount(2);
    }

    public function test_it_returns_a_safe_json_error_when_league_preview_fails(): void
    {
        config()->set('services.stratz.token', 'test-token');
        config()->set('services.stratz.endpoint', 'https://api.stratz.com/graphql');

        Http::fake([
            'https://api.stratz.com/graphql' => Http::response([
                'errors' => [['message' => 'Invalid League ID']],
            ]),
        ]);

        $response = $this->authenticateStatically()->postJson(route('stratz.import-batches.league-preview'), [
            'league_id' => 19785,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('error', 'STRATZ GraphQL error: Invalid League ID');
        $response->assertJsonMissingPath('exception');
    }
}
