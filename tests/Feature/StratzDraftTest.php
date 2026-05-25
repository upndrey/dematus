<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StratzDraftTest extends TestCase
{
    public function test_draft_request_builds_payload_from_match_id(): void
    {
        config()->set('services.stratz.token', 'test-token');

        Http::fake(function (Request $request) {
            $query = (string) $request['query'];

            if (str_contains($query, 'query MatchById')) {
                return Http::response([
                    'data' => [
                        'match' => [
                            'id' => 8294471853,
                            'didRadiantWin' => true,
                            'durationSeconds' => 2120,
                            'gameMode' => 'SINGLE_DRAFT',
                            'gameVersionId' => 179,
                            'players' => [
                                ['steamAccountId' => 601, 'heroId' => 6, 'isRadiant' => false, 'position' => 'POSITION_1'],
                                ['steamAccountId' => 101, 'heroId' => 1, 'isRadiant' => true, 'position' => 'POSITION_1'],
                                ['steamAccountId' => 602, 'heroId' => 7, 'isRadiant' => false, 'position' => 'POSITION_2'],
                                ['steamAccountId' => 102, 'heroId' => 2, 'isRadiant' => true, 'position' => 'POSITION_2'],
                                ['steamAccountId' => 603, 'heroId' => 8, 'isRadiant' => false, 'position' => 'POSITION_3'],
                                ['steamAccountId' => 103, 'heroId' => 3, 'isRadiant' => true, 'position' => 'POSITION_3'],
                                ['steamAccountId' => 604, 'heroId' => 9, 'isRadiant' => false, 'position' => 'POSITION_4'],
                                ['steamAccountId' => 104, 'heroId' => 4, 'isRadiant' => true, 'position' => 'POSITION_4'],
                                ['steamAccountId' => 605, 'heroId' => 10, 'isRadiant' => false, 'position' => 'POSITION_5'],
                                ['steamAccountId' => 105, 'heroId' => 5, 'isRadiant' => true, 'position' => 'POSITION_5'],
                            ],
                            'pickBans' => [
                                ['bannedHeroId' => 41],
                                ['bannedHeroId' => null],
                                ['bannedHeroId' => 42],
                            ],
                        ],
                    ],
                ]);
            }

            if (str_contains($query, 'query Draft')) {
                return Http::response([
                    'data' => [
                        'plus' => [
                            'draft' => [
                                'midOutcome' => 0.5,
                                'safeOutcome' => 0.6,
                                'offOutcome' => 0.4,
                                'winValues' => [0.61, 0.6, 0.59, 0.58, 0.57, 0.56, 0.55, 0.54, 0.53, 0.52, 0.51, 0.5, 0.49, 0.48, 0.47, 0.42, 0.91],
                                'durationValues' => [35.1],
                                'players' => [],
                            ],
                        ],
                    ],
                ]);
            }

            return Http::response([], 500);
        });

        $response = $this->withSession([config('static-auth.session_key') => true])
            ->postJson(route('stratz.draft'), [
                'match_id' => 8294471853,
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'type' => 'draft',
                'data' => [
                    'formatted' => [
                        'match_id' => 8294471853,
                        'winner' => 'radiant',
                        'radiant_odds_1' => 0.647,
                        'radiant_odds_2' => 0.947,
                        'dire_odds_1' => 0.353,
                        'dire_odds_2' => 0.053,
                    ],
                    'request' => [
                        'matchId' => 8294471853,
                        'gameMode' => 4,
                        'gameVersionId' => 179,
                        'bans' => [41, 42],
                        'players' => [
                            ['slot' => 0, 'heroId' => 1, 'steamAccountId' => 101, 'position' => 'POSITION_1'],
                            ['slot' => 1, 'heroId' => 2, 'steamAccountId' => 102, 'position' => 'POSITION_2'],
                            ['slot' => 2, 'heroId' => 3, 'steamAccountId' => 103, 'position' => 'POSITION_3'],
                            ['slot' => 3, 'heroId' => 4, 'steamAccountId' => 104, 'position' => 'POSITION_4'],
                            ['slot' => 4, 'heroId' => 5, 'steamAccountId' => 105, 'position' => 'POSITION_5'],
                            ['slot' => 5, 'heroId' => 6, 'steamAccountId' => 601, 'position' => 'POSITION_1'],
                            ['slot' => 6, 'heroId' => 7, 'steamAccountId' => 602, 'position' => 'POSITION_2'],
                            ['slot' => 7, 'heroId' => 8, 'steamAccountId' => 603, 'position' => 'POSITION_3'],
                            ['slot' => 8, 'heroId' => 9, 'steamAccountId' => 604, 'position' => 'POSITION_4'],
                            ['slot' => 9, 'heroId' => 10, 'steamAccountId' => 605, 'position' => 'POSITION_5'],
                        ],
                    ],
                    'raw' => [
                        'midOutcome' => 0.5,
                        'safeOutcome' => 0.6,
                        'offOutcome' => 0.4,
                    ],
                ],
            ]);

        Http::assertSentCount(2);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains((string) $request['query'], 'query Draft')) {
                return false;
            }

            return $request->hasHeader('User-Agent', 'STRATZ_API')
                && $request['variables']['request']['matchId'] === 8294471853
                && $request['variables']['request']['gameMode'] === 4
                && $request['variables']['request']['gameVersionId'] === 179
                && $request['variables']['request']['bans'] === [41, 42]
                && $request['variables']['request']['players'] === [
                    ['slot' => 0, 'heroId' => 1, 'steamAccountId' => 101, 'position' => 'POSITION_1'],
                    ['slot' => 1, 'heroId' => 2, 'steamAccountId' => 102, 'position' => 'POSITION_2'],
                    ['slot' => 2, 'heroId' => 3, 'steamAccountId' => 103, 'position' => 'POSITION_3'],
                    ['slot' => 3, 'heroId' => 4, 'steamAccountId' => 104, 'position' => 'POSITION_4'],
                    ['slot' => 4, 'heroId' => 5, 'steamAccountId' => 105, 'position' => 'POSITION_5'],
                    ['slot' => 5, 'heroId' => 6, 'steamAccountId' => 601, 'position' => 'POSITION_1'],
                    ['slot' => 6, 'heroId' => 7, 'steamAccountId' => 602, 'position' => 'POSITION_2'],
                    ['slot' => 7, 'heroId' => 8, 'steamAccountId' => 603, 'position' => 'POSITION_3'],
                    ['slot' => 8, 'heroId' => 9, 'steamAccountId' => 604, 'position' => 'POSITION_4'],
                    ['slot' => 9, 'heroId' => 10, 'steamAccountId' => 605, 'position' => 'POSITION_5'],
                ];
        });
    }

    public function test_draft_request_returns_clear_error_for_unsupported_heroes(): void
    {
        config()->set('services.stratz.token', 'test-token');

        Http::fake(function (Request $request) {
            $query = (string) $request['query'];

            if (str_contains($query, 'query MatchById')) {
                return Http::response([
                    'data' => [
                        'match' => [
                            'id' => 8294471853,
                            'didRadiantWin' => true,
                            'durationSeconds' => 2120,
                            'gameMode' => 'SINGLE_DRAFT',
                            'gameVersionId' => 179,
                            'players' => [
                                ['steamAccountId' => 101, 'heroId' => 145, 'isRadiant' => true, 'position' => 'POSITION_1'],
                                ['steamAccountId' => 102, 'heroId' => 2, 'isRadiant' => true, 'position' => 'POSITION_2'],
                                ['steamAccountId' => 103, 'heroId' => 3, 'isRadiant' => true, 'position' => 'POSITION_3'],
                                ['steamAccountId' => 104, 'heroId' => 4, 'isRadiant' => true, 'position' => 'POSITION_4'],
                                ['steamAccountId' => 105, 'heroId' => 5, 'isRadiant' => true, 'position' => 'POSITION_5'],
                                ['steamAccountId' => 601, 'heroId' => 6, 'isRadiant' => false, 'position' => 'POSITION_1'],
                                ['steamAccountId' => 602, 'heroId' => 7, 'isRadiant' => false, 'position' => 'POSITION_2'],
                                ['steamAccountId' => 603, 'heroId' => 8, 'isRadiant' => false, 'position' => 'POSITION_3'],
                                ['steamAccountId' => 604, 'heroId' => 9, 'isRadiant' => false, 'position' => 'POSITION_4'],
                                ['steamAccountId' => 605, 'heroId' => 10, 'isRadiant' => false, 'position' => 'POSITION_5'],
                            ],
                            'pickBans' => [],
                        ],
                    ],
                ]);
            }

            return Http::response([], 500);
        });

        $response = $this->withSession([config('static-auth.session_key') => true])
            ->postJson(route('stratz.draft'), [
                'match_id' => 8294471853,
            ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'error' => 'STRATZ Plus Draft currently does not support these heroes: Kez.',
            ]);

        Http::assertSentCount(1);
    }
}
