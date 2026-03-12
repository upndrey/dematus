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
        config()->set('services.google_sheets.spreadsheet_url', 'https://docs.google.com/spreadsheets/d/test-sheet-id/edit?gid=0');
        config()->set('services.google_sheets.service_account_credentials', $this->fakeGoogleCredentialsPath());
        config()->set('services.google_sheets.timeout', 20);

        $matchId = 8683333901;
        $picks = $this->roshPicks();
        $match = $this->fakeRoshMatch($picks, $matchId);
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
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response([
                    'access_token' => 'google-access-token',
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                ]);
            }

            if (
                str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id')
                && str_contains($request->url(), 'fields=')
                && ! str_contains($request->url(), 'values:')
            ) {
                return Http::response([
                    'sheets' => [
                        [
                            'properties' => [
                                'sheetId' => 0,
                                'title' => 'BLAST SLAM VI',
                                'index' => 0,
                            ],
                        ],
                    ],
                ]);
            }

            if (str_contains(rawurldecode($request->url()), "/values/'BLAST SLAM VI'!B:B")) {
                return Http::response([
                    'range' => "'BLAST SLAM VI'!B:B",
                    'values' => [
                        ['ROSH Winrate'],
                        ['Match ID'],
                        ['8678737586'],
                        ['8678680298'],
                        ['8678799687'],
                        ['8678990124'],
                        ['8679012467'],
                        ['8683333901'],
                    ],
                ]);
            }

            if (str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id/values:batchUpdate')) {
                return Http::response([
                    'totalUpdatedRows' => 2,
                ]);
            }

            if (str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id/values:batchGet')) {
                return Http::response([
                    'valueRanges' => [
                        ['range' => "'BLAST SLAM VI'!B8", 'values' => [['8683333901']]],
                        ['range' => "'BLAST SLAM VI'!C8", 'values' => [['Team Liquid']]],
                        ['range' => "'BLAST SLAM VI'!D8", 'values' => [['GamerLegion']]],
                        ['range' => "'BLAST SLAM VI'!E8", 'values' => [['Radiant']]],
                        ['range' => "'BLAST SLAM VI'!G8", 'values' => [['3,3%']]],
                        ['range' => "'BLAST SLAM VI'!H8", 'values' => [['2,8%']]],
                        ['range' => "'BLAST SLAM VI'!J8", 'values' => [['0,0%']]],
                        ['range' => "'BLAST SLAM VI'!K8", 'values' => [['0,0%']]],
                    ],
                ]);
            }

            $query = $request->offsetExists('query')
                ? (string) $request['query']
                : '';

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
            'match_id' => $matchId,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('type', 'rosh')
            ->assertJsonPath('data.formatted.match_id', $matchId)
            ->assertJsonPath('data.formatted.winner', 'radiant')
            ->assertJsonPath('data.formatted.radiant_team', 'Team Liquid')
            ->assertJsonPath('data.formatted.dire_team', 'GamerLegion')
            ->assertJsonPath('data.formatted.bracket', 'IMMORTAL')
            ->assertJsonPath('data.formatted.bracket_basic', 'DIVINE_IMMORTAL')
            ->assertJsonPath('data.formatted.date_time', 1770574943)
            ->assertJsonPath('data.formatted.radiant_odds_1', 3.3)
            ->assertJsonPath('data.formatted.radiant_odds_2', 2.8)
            ->assertJsonPath('data.formatted.dire_odds_1', 0)
            ->assertJsonPath('data.formatted.dire_odds_2', 0)
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
            ->assertJsonPath('data.request.match.variables.matchId', $matchId)
            ->assertJsonPath('data.request.analysis.bracket', 'IMMORTAL')
            ->assertJsonPath('data.request.analysis.bracketBasicIds', 'DIVINE_IMMORTAL')
            ->assertJsonPath('data.request.analysis.week', 1770574943)
            ->assertJsonPath('data.google_sheets.sheet_title', 'BLAST SLAM VI')
            ->assertJsonPath('data.google_sheets.row', 8)
            ->assertJsonPath('data.google_sheets.cells.B8', '8683333901')
            ->assertJsonPath('data.google_sheets.cells.C8', 'Team Liquid')
            ->assertJsonPath('data.google_sheets.cells.D8', 'GamerLegion')
            ->assertJsonPath('data.google_sheets.cells.E8', 'Radiant')
            ->assertJsonPath('data.google_sheets.cells.G8', '3,3%')
            ->assertJsonPath('data.google_sheets.cells.H8', '2,8%')
            ->assertJsonPath('data.google_sheets.cells.J8', '0,0%')
            ->assertJsonPath('data.google_sheets.cells.K8', '0,0%')
            ->assertJsonPath('data.raw.match.id', $matchId)
            ->assertJsonPath('data.raw.analysis_summary.hero_stats_by_time_global.heroStatsByTime_1.count', 4)
            ->assertJsonPath('data.raw.analysis_summary.synergy.matchUp_Prev_Week_1.count', 0)
            ->assertJsonMissingPath('data.raw.analysis');

        Http::assertSentCount(10);

        Http::assertSent(function (Request $request) use ($matchId): bool {
            if ($request->url() !== 'https://api.stratz.com/graphql') {
                return false;
            }

            return str_contains((string) $request['query'], 'query GetMatchPicksBans')
                && $request->hasHeader('User-Agent', 'STRATZ_API')
                && $request['variables']['matchId'] === $matchId;
        });

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://api.stratz.com/graphql') {
                return false;
            }

            return str_contains((string) $request['query'], 'query HeroesMetaPositionsByWeek')
                && $request['variables']['bracketBasicIds'] === 'DIVINE_IMMORTAL'
                && $request['variables']['week'] === 1770574943;
        });

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://api.stratz.com/graphql') {
                return false;
            }

            if (! str_contains((string) $request['query'], 'query GetHeroStatsByTime')) {
                return false;
            }

            return $request['variables']['week'] === 1770574943;
        });

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://api.stratz.com/graphql') {
                return false;
            }

            return str_contains((string) $request['query'], 'query Synergy')
                && $request['variables']['bracketBasicIds'] === 'DIVINE_IMMORTAL'
                && $request['variables']['matchLimit'] === 0
                && $request['variables']['take'] === 200
                && $request['variables']['currentWeek'] === 1770574943
                && $request['variables']['previousWeek1'] === 1769970143
                && $request['variables']['previousWeek2'] === 1769365343
                && $request['variables']['previousWeek3'] === 1768760543;
        });

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && is_string($request['assertion']);
        });

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id')
                && str_contains($request->url(), 'fields=')
                && ! str_contains($request->url(), 'values:')
                && $request->hasHeader('Authorization', 'Bearer google-access-token');
        });

        Http::assertSent(function (Request $request): bool {
            return str_contains(rawurldecode($request->url()), "/values/'BLAST SLAM VI'!B:B")
                && $request->hasHeader('Authorization', 'Bearer google-access-token');
        });

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id/values:batchUpdate')
                && $request->hasHeader('Authorization', 'Bearer google-access-token')
                && $request['valueInputOption'] === 'USER_ENTERED'
                && $request['data'] === [
                    [
                        'range' => "'BLAST SLAM VI'!B8:E8",
                        'majorDimension' => 'ROWS',
                        'values' => [['8683333901', 'Team Liquid', 'GamerLegion', 'Radiant']],
                    ],
                    [
                        'range' => "'BLAST SLAM VI'!G8:H8",
                        'majorDimension' => 'ROWS',
                        'values' => [['3,3%', '2,8%']],
                    ],
                    [
                        'range' => "'BLAST SLAM VI'!J8:K8",
                        'majorDimension' => 'ROWS',
                        'values' => [['0,0%', '0,0%']],
                    ],
                ];
        });

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id/values:batchGet')
                && $request->hasHeader('Authorization', 'Bearer google-access-token');
        });
    }

    public function test_rosh_request_appends_new_google_sheets_row_when_match_id_is_missing(): void
    {
        config()->set('services.stratz.token', 'test-token');
        config()->set('services.google_sheets.spreadsheet_url', 'https://docs.google.com/spreadsheets/d/test-sheet-id/edit?gid=0');
        config()->set('services.google_sheets.service_account_credentials', $this->fakeGoogleCredentialsPath());
        config()->set('services.google_sheets.timeout', 20);

        $matchId = 9999999999;
        $picks = $this->roshPicks();
        $match = $this->fakeRoshMatch($picks, $matchId);
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
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response([
                    'access_token' => 'google-access-token',
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                ]);
            }

            if (
                str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id')
                && str_contains($request->url(), 'fields=')
                && ! str_contains($request->url(), 'values:')
            ) {
                return Http::response([
                    'sheets' => [
                        [
                            'properties' => [
                                'sheetId' => 0,
                                'title' => 'BLAST SLAM VI',
                                'index' => 0,
                            ],
                        ],
                    ],
                ]);
            }

            if (str_contains(rawurldecode($request->url()), "/values/'BLAST SLAM VI'!B:B")) {
                return Http::response([
                    'range' => "'BLAST SLAM VI'!B:B",
                    'values' => [
                        ['ROSH Winrate'],
                        ['Match ID'],
                        ['8678737586'],
                        ['8678680298'],
                        ['8678799687'],
                        ['8678990124'],
                        ['8679012467'],
                        ['8683333901'],
                    ],
                ]);
            }

            if (str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id/values:batchUpdate')) {
                return Http::response([
                    'totalUpdatedRows' => 3,
                ]);
            }

            if (str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id/values:batchGet')) {
                return Http::response([
                    'valueRanges' => [
                        ['range' => "'BLAST SLAM VI'!B9", 'values' => [['9999999999']]],
                        ['range' => "'BLAST SLAM VI'!C9", 'values' => [['Team Liquid']]],
                        ['range' => "'BLAST SLAM VI'!D9", 'values' => [['GamerLegion']]],
                        ['range' => "'BLAST SLAM VI'!E9", 'values' => [['Radiant']]],
                        ['range' => "'BLAST SLAM VI'!G9", 'values' => [['3,3%']]],
                        ['range' => "'BLAST SLAM VI'!H9", 'values' => [['2,8%']]],
                        ['range' => "'BLAST SLAM VI'!J9", 'values' => [['0,0%']]],
                        ['range' => "'BLAST SLAM VI'!K9", 'values' => [['0,0%']]],
                    ],
                ]);
            }

            $query = $request->offsetExists('query')
                ? (string) $request['query']
                : '';

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
            'match_id' => $matchId,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.formatted.match_id', $matchId)
            ->assertJsonPath('data.google_sheets.row', 9)
            ->assertJsonPath('data.google_sheets.cells.B9', '9999999999')
            ->assertJsonPath('data.google_sheets.cells.C9', 'Team Liquid')
            ->assertJsonPath('data.google_sheets.cells.D9', 'GamerLegion')
            ->assertJsonPath('data.google_sheets.cells.E9', 'Radiant')
            ->assertJsonPath('data.google_sheets.cells.G9', '3,3%')
            ->assertJsonPath('data.google_sheets.cells.H9', '2,8%')
            ->assertJsonPath('data.google_sheets.cells.J9', '0,0%')
            ->assertJsonPath('data.google_sheets.cells.K9', '0,0%');

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id/values:batchUpdate')
                && $request->hasHeader('Authorization', 'Bearer google-access-token')
                && $request['data'] === [
                    [
                        'range' => "'BLAST SLAM VI'!B9:E9",
                        'majorDimension' => 'ROWS',
                        'values' => [['9999999999', 'Team Liquid', 'GamerLegion', 'Radiant']],
                    ],
                    [
                        'range' => "'BLAST SLAM VI'!G9:H9",
                        'majorDimension' => 'ROWS',
                        'values' => [['3,3%', '2,8%']],
                    ],
                    [
                        'range' => "'BLAST SLAM VI'!J9:K9",
                        'majorDimension' => 'ROWS',
                        'values' => [['0,0%', '0,0%']],
                    ],
                ];
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
    private function fakeRoshMatch(array $picks, int $matchId = 8683333901): array
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
            'id' => $matchId,
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

    private function fakeGoogleCredentialsPath(): string
    {
        $directory = storage_path('framework/testing');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory.'/google-sheets-service-account.json';
        $privateKey = <<<'KEY'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDVDgMxpgtd88yp
JtDxzBf71lx499ajbRIcFvGy+WuJXDFE3x2/t9lNJgwSUc4Geo1nvy5NwEJ9HdxM
DAP8RduQYUXWSIWx+Os42Ht3SSf+yc4Z/w3uZejgn0tbFB+ssGRePgAik4Jw9c0e
FiPCKo4zem0hcWK90SwWcq6DcwKWTgYu6jx207plShbOKMOKwFfHdlbTRbkPpGPF
00RjKewuqoL5qR8Gjh1VokJAewJkDCRZuNG/FaHDFqe0uh8zIzknlm1pdo+r7Op1
LRe2h59UMDpd3Yd89hOdwB9T7vloSUy3ghVOIkU2syQxTnnuZBN32JMLeT3UN037
L1Z4APB/AgMBAAECggEAPO4ddrj0XkGngaaSsdv67fBPkN7cGO/L8pGTPOp82RMv
GfGvCdGzyQ78+84+W/b3crinrt+xhCOiiXMUPrThxogzF0W1SoEUEDVFFgercv/W
u/OH0ep+L6MOw3TdXB80DQmxMzI5Z0G8kAKn5lMSSOGVzS8mnH9yGtdgVkJxdndD
OxgW1Rl5w6rvTbplvPyjsuTq3eo9ibk10Tj+zxB1rhrcIjUrwanjAMq6kEz0MIED
tlScKNSzTfj7YusT5+41BGiCjQlfbGG7QTaOKzgc9S9uO8u3jQYEPEO0zEaxr1hE
I3sG2uWzX4g7H0BJL2PAJh0HWubKT0IgPeyYobJ1XQKBgQDyl9YJ3ejXmhtAaWGq
+grw7M9chTVllW6SZ4VwHbH+ci3RpWfSMyH4SUg06blbcorPHSCXcJglqKboO7vC
LuTuxL3TeNGi7mcirbqCtkh3Cf5od7SM3yOzyEioZHkGX4XyMbbM90r66tJbfCRY
He9bXECt5jAV0ahiG3BFxPlsCwKBgQDg1EXT8HWZhORev0j1Gg5p4sqTal7DKnW/
TjdtpunadLyj0dR0IZ36AAdC40clgaFKpXuj2VOxn3ZjVq0midFnQl13w9urdiH8
wyeMjlsWUw3KA8zi+gZJs+hlCSjtjLit8ntQBDmYgOSsttbLNxGKyG3ojDIsJ59P
2xBHmMvh3QKBgFpsVg+fc2bJvlan8Qu139YlrrUhweF3bZuMkqRTrUDWdlWqfaRQ
At11E0EFzV1UuICyrq9D/LIsxunROg8LQ7HsC4WDh0Bf9HlsoBSQtToJs5Zk6BuK
INimUs7RhHrnqBm6hhSoKH6WgIoxH6ronYtEO6eWIV5Ao67N429eGEo5AoGAcJBW
1aHSfyZV4EoNEQoWpVTy75OWFkiv4zQZ9EBZXRKNT8fCgtJB8eUJvadk+5ZHVsQ7
fvFUQd4AvAOtdVoTCYvkmA3rcZEXuyFKL8kmOasjgD0e25UqiMQWWl+XqjeGTzDU
JF+5Jm2CECcKq3vKwJ1QydlHVWwRCz42jGIn0dECgYEAy14F/a7gjxK1aou6BSxW
yqkB9hOuDYDIE8SiJlmLy7W0Unod8F/ow4d4B7AshDyJ6CNekv3ZV3ig6XK5d63K
fmNizx22XWdbdMrsR2JeZEVmQ8yoGaeRCcY7a04I7zTIS52EVlz3SgoD6/JZ8ztc
Nr1txDw/hxyBXINrkRvaHCo=
-----END PRIVATE KEY-----
KEY;

        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'project_id' => 'test-project',
            'private_key_id' => 'test-private-key-id',
            'private_key' => $privateKey,
            'client_email' => 'test-service-account@example.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}
