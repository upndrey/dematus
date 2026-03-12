<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StratzRoshTest extends TestCase
{
    public function test_rosh_request_builds_analysis_from_match_id(): void
    {
        config()->set('services.stratz.token', 'test-token');

        $picks = $this->roshPicks();
        $match = $this->fakeRoshMatch($picks);
        $metaPositions = $this->fakeRoshMetaPositions($picks);
        $globalTimeStats = $this->fakeRoshHeroStatsByTime(
            $picks,
            [37 => 0.0],
            [37 => [20 => 2100, 21 => 1000]],
        );
        $bracketTimeStats = $this->fakeRoshHeroStatsByTime(
            $picks,
            [],
            [37 => [20 => 2100, 21 => 900]],
        );

        Http::fake(function (Request $request) use ($match, $metaPositions, $globalTimeStats, $bracketTimeStats) {
            $query = (string) $request['query'];

            if (str_contains($query, 'query GetMatchPicksBans')) {
                return Http::response([
                    'data' => [
                        'match' => $match,
                    ],
                ]);
            }

            if (str_contains($query, 'query HeroesMetaPositionsByWeek')) {
                return Http::response([
                    'data' => [
                        'heroStats' => $metaPositions,
                    ],
                ]);
            }

            if (str_contains($query, 'query GetHeroStatsByTime')) {
                $heroStats = isset($request['variables']['bracketBasicIds'])
                    ? $bracketTimeStats
                    : $globalTimeStats;

                return Http::response([
                    'data' => [
                        'heroStats' => $heroStats,
                    ],
                ]);
            }

            if (str_contains($query, 'query Synergy')) {
                return Http::response([
                    'data' => [
                        'heroStats' => [
                            'matchUp_Prev_Week_1' => [],
                            'matchUp_Prev_Week_2' => [],
                            'matchUp_Prev_Week_3' => [],
                            'matchUp_Prev_Week_4' => [],
                        ],
                    ],
                ]);
            }

            return Http::response([], 500);
        });

        $response = $this->postJson(route('stratz.rosh'), [
            'match_id' => 8683333901,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('type', 'rosh')
            ->assertJsonPath('data.formatted.match_id', 8683333901)
            ->assertJsonPath('data.formatted.winner', 'radiant')
            ->assertJsonPath('data.formatted.radiant_team', 'Team Liquid')
            ->assertJsonPath('data.formatted.dire_team', 'GamerLegion')
            ->assertJsonPath('data.formatted.bracket', 'IMMORTAL')
            ->assertJsonPath('data.formatted.bracket_basic', 'DIVINE_IMMORTAL')
            ->assertJsonPath('data.formatted.date_time', 1770574943)
            ->assertJsonCount(2, 'data.minute_table')
            ->assertJsonPath('data.minute_table.0.minute', 20)
            ->assertJsonPath('data.minute_table.0.time_start', 20)
            ->assertJsonPath('data.minute_table.0.time_end', 21)
            ->assertJsonPath('data.minute_table.0.advantage_side', 'radiant')
            ->assertJsonPath('data.minute_table.0.advantage_percent', 3.3)
            ->assertJsonPath('data.minute_table.0.radiant_advantage', 3.3)
            ->assertJsonPath('data.minute_table.0.dire_advantage', 0)
            ->assertJsonPath('data.minute_table.1.minute', 21)
            ->assertJsonPath('data.minute_table.1.time_start', 20)
            ->assertJsonPath('data.minute_table.1.time_end', 22)
            ->assertJsonPath('data.minute_table.1.advantage_side', 'radiant')
            ->assertJsonPath('data.minute_table.1.advantage_percent', 2.8)
            ->assertJsonPath('data.minute_table.1.radiant_advantage', 2.8)
            ->assertJsonPath('data.minute_table.1.dire_advantage', 0)
            ->assertJsonPath('data.request.match.operationName', 'GetMatchPicksBans')
            ->assertJsonPath('data.request.match.variables.matchId', 8683333901)
            ->assertJsonPath('data.request.analysis.bracket', 'IMMORTAL')
            ->assertJsonPath('data.request.analysis.bracketBasicIds', 'DIVINE_IMMORTAL')
            ->assertJsonPath('data.request.analysis.week', 1770574943)
            ->assertJsonPath('data.raw.match.id', 8683333901);

        Http::assertSentCount(5);

        Http::assertSent(function (Request $request): bool {
            return str_contains((string) $request['query'], 'query GetMatchPicksBans')
                && $request->hasHeader('User-Agent', 'STRATZ_API')
                && $request['variables']['matchId'] === 8683333901;
        });

        Http::assertSent(function (Request $request): bool {
            return str_contains((string) $request['query'], 'query HeroesMetaPositionsByWeek')
                && $request['variables']['bracketBasicIds'] === 'DIVINE_IMMORTAL'
                && $request['variables']['week'] === 1770574943;
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_contains((string) $request['query'], 'query GetHeroStatsByTime')) {
                return false;
            }

            return $request['variables']['week'] === 1770574943;
        });

        Http::assertSent(function (Request $request): bool {
            return str_contains((string) $request['query'], 'query Synergy')
                && $request['variables']['bracketBasicIds'] === 'DIVINE_IMMORTAL'
                && $request['variables']['matchLimit'] === 0
                && $request['variables']['take'] === 200
                && $request['variables']['currentWeek'] === 1770574943
                && $request['variables']['previousWeek1'] === 1769970143
                && $request['variables']['previousWeek2'] === 1769365343
                && $request['variables']['previousWeek3'] === 1768760543;
        });
    }

    public function test_rosh_request_requires_match_id(): void
    {
        $response = $this->postJson(route('stratz.rosh'), []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['match_id']);
    }

    /**
     * @return list<array{heroId:int, positionId:int, isRadiant:bool, baseDiff:float}>
     */
    private function roshPicks(): array
    {
        return [
            ['heroId' => 114, 'positionId' => 1, 'isRadiant' => true, 'baseDiff' => 10.0],
            ['heroId' => 25, 'positionId' => 2, 'isRadiant' => true, 'baseDiff' => 5.0],
            ['heroId' => 23, 'positionId' => 3, 'isRadiant' => true, 'baseDiff' => 2.0],
            ['heroId' => 79, 'positionId' => 4, 'isRadiant' => true, 'baseDiff' => 0.0],
            ['heroId' => 112, 'positionId' => 5, 'isRadiant' => true, 'baseDiff' => 1.0],
            ['heroId' => 70, 'positionId' => 1, 'isRadiant' => false, 'baseDiff' => -2.0],
            ['heroId' => 59, 'positionId' => 2, 'isRadiant' => false, 'baseDiff' => -4.0],
            ['heroId' => 39, 'positionId' => 3, 'isRadiant' => false, 'baseDiff' => -1.0],
            ['heroId' => 83, 'positionId' => 4, 'isRadiant' => false, 'baseDiff' => -3.0],
            ['heroId' => 37, 'positionId' => 5, 'isRadiant' => false, 'baseDiff' => -5.0],
        ];
    }

    /**
     * @param  list<array{heroId:int, positionId:int, isRadiant:bool, baseDiff:float}>  $picks
     * @return array<string, mixed>
     */
    private function fakeRoshMatch(array $picks): array
    {
        $players = array_map(
            fn (array $pick) => [
                'heroId' => $pick['heroId'],
                'position' => 'POSITION_'.$pick['positionId'],
            ],
            $picks,
        );

        $pickBans = array_map(
            fn (array $pick, int $index) => [
                'heroId' => $pick['heroId'],
                'order' => $index,
                'isPick' => true,
                'isRadiant' => $pick['isRadiant'],
                'bannedHeroId' => null,
                'wasBannedSuccessfully' => null,
            ],
            $picks,
            array_keys($picks),
        );

        return [
            'id' => 8683333901,
            'didRadiantWin' => true,
            'endDateTime' => 1770574943,
            'bracket' => 8,
            'radiantTeam' => [
                'id' => 2163,
                'name' => 'Team Liquid',
            ],
            'direTeam' => [
                'id' => 9964962,
                'name' => 'GamerLegion',
            ],
            'players' => $players,
            'pickBans' => $pickBans,
        ];
    }

    /**
     * @param  list<array{heroId:int, positionId:int, isRadiant:bool, baseDiff:float}>  $picks
     * @return array<string, mixed>
     */
    private function fakeRoshMetaPositions(array $picks): array
    {
        $heroStats = [
            'heroes' => [],
        ];

        foreach (range(1, 5) as $positionId) {
            $heroStats['heroesPos_'.$positionId] = [];
        }

        foreach ($picks as $pick) {
            $matchCount = 2000;
            $winCount = (int) round($matchCount * ((50 + $pick['baseDiff']) / 100));
            $row = [
                'heroId' => $pick['heroId'],
                'matchCount' => $matchCount,
                'winCount' => $winCount,
            ];

            $heroStats['heroesPos_'.$pick['positionId']][] = $row;
            $heroStats['heroes'][] = $row;
        }

        return $heroStats;
    }

    /**
     * @param  list<array{heroId:int, positionId:int, isRadiant:bool, baseDiff:float}>  $picks
     * @param  array<int, float>  $baseDiffOverrides
     * @param  array<int, array<int, int>>  $matchCountOverrides
     * @return array<string, list<array{heroId:int, time:int, winCount:int, matchCount:int}>>
     */
    private function fakeRoshHeroStatsByTime(
        array $picks,
        array $baseDiffOverrides = [],
        array $matchCountOverrides = [],
    ): array {
        $heroStats = [];

        foreach (range(1, 5) as $positionId) {
            $rows = [];

            foreach ($picks as $pick) {
                if ($pick['positionId'] !== $positionId) {
                    continue;
                }

                $baseDiff = $baseDiffOverrides[$pick['heroId']] ?? $pick['baseDiff'];
                $rate = (50 + $baseDiff) / 100;
                $time20MatchCount = $matchCountOverrides[$pick['heroId']][20] ?? 2200;
                $time21MatchCount = $matchCountOverrides[$pick['heroId']][21] ?? 1000;

                $rows[] = [
                    'heroId' => $pick['heroId'],
                    'time' => 20,
                    'winCount' => (int) round($time20MatchCount * $rate),
                    'matchCount' => $time20MatchCount,
                ];

                $rows[] = [
                    'heroId' => $pick['heroId'],
                    'time' => 21,
                    'winCount' => (int) round($time21MatchCount * $rate),
                    'matchCount' => $time21MatchCount,
                ];
            }

            usort(
                $rows,
                static fn (array $left, array $right): int => [$left['heroId'], $left['time']] <=> [$right['heroId'], $right['time']],
            );

            $heroStats['heroStatsByTime_'.$positionId] = $rows;
        }

        return $heroStats;
    }
}
