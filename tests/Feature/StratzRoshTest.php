<?php

namespace Tests\Feature;

use App\Models\DraftSnapshot;
use App\Services\Stratz\StratzService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StratzRoshTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticateStatically();
    }

    use RefreshDatabase;

    public function test_rosh_request_builds_analysis_from_match_id(): void
    {
        config()->set('services.stratz.token', 'test-token');
        config()->set('services.google_sheets.spreadsheet_url', 'https://docs.google.com/spreadsheets/d/test-sheet-id/edit?gid=0');
        config()->set('services.google_sheets.service_account_credentials', $this->fakeGoogleCredentialsPath());
        config()->set('services.google_sheets.timeout', 20);
        config()->set('services.google_sheets.dataset_sheet', 'BLAST SLAM VI');

        $matchId = 8683333901;
        $picks = $this->roshPicks();
        $match = $this->fakeRoshMatch($picks, $matchId);
        $match['pickBans'] = null;
        $match['players'] = array_map(
            fn (array $pick): array => [
                'heroId' => $pick['heroId'],
                'position' => null,
                'isRadiant' => $pick['isRadiant'],
            ],
            $picks,
        );
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
                        [
                            'properties' => [
                                'sheetId' => 455676083,
                                'title' => 'Dataset',
                                'index' => 7,
                            ],
                        ],
                    ],
                ]);
            }

            if (str_contains(rawurldecode($request->url()), "/values/'Dataset'!A:")) {
                return Http::response([
                    'range' => "'Dataset'!A:AZ",
                    'values' => [
                        ['snapshot_id'],
                    ],
                ]);
            }

            if (
                str_contains(rawurldecode($request->url()), "/values/'BLAST SLAM VI'!A:")
                && ! str_contains(rawurldecode($request->url()), "/values/'BLAST SLAM VI'!A:A")
            ) {
                return Http::response(['values' => []]);
            }

            if (str_contains(rawurldecode($request->url()), "/values/'BLAST SLAM VI'!A:A")) {
                return Http::response([
                    'range' => "'BLAST SLAM VI'!A:A",
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

            if (str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id:batchUpdate')) {
                return Http::response([
                    'replies' => [],
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
                        ['range' => "'BLAST SLAM VI'!A8", 'values' => [['8683333901']]],
                        ['range' => "'BLAST SLAM VI'!C8", 'values' => [['Team Liquid']]],
                        ['range' => "'BLAST SLAM VI'!D8", 'values' => [['GamerLegion']]],
                        ['range' => "'BLAST SLAM VI'!E8", 'values' => [['Radiant']]],
                        ['range' => "'BLAST SLAM VI'!F8", 'values' => [['10,6%']]],
                        ['range' => "'BLAST SLAM VI'!G8", 'values' => [['10,6%']]],
                        ['range' => "'BLAST SLAM VI'!I8", 'values' => [['0,0%']]],
                        ['range' => "'BLAST SLAM VI'!J8", 'values' => [['0,0%']]],
                    ],
                ]);
            }

            if (str_contains($request->url(), 'https://sheets.googleapis.com/')) {
                return Http::response([]);
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

            if (str_contains($query, 'query HeroesMetaPositionsByWeeks')) {
                preg_match_all('/week_(\d+): heroStats/', $query, $matches);

                return Http::response([
                    'data' => array_fill_keys($matches[1], $metaPositions),
                ]);
            }

            if (str_contains($query, 'query GetHeroStatsByTimeForWeeks')) {
                preg_match_all('/week_(\d+): heroStats/', $query, $matches);

                return Http::response([
                    'data' => array_fill_keys($matches[1], $bracketTimeStats),
                ]);
            }

            if (str_contains($query, 'query SynergyForWeeks')) {
                preg_match_all('/week_(\d+): heroStats/', $query, $matches);

                return Http::response([
                    'data' => array_fill_keys($matches[1], [
                        'matchUp_Prev_Week_1' => [],
                        'matchUp_Prev_Week_2' => [],
                        'matchUp_Prev_Week_3' => [],
                        'matchUp_Prev_Week_4' => [],
                    ]),
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

        $response = $this
            ->withSession([config('static-auth.session_key') => true])
            ->postJson(route('stratz.rosh'), [
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
            ->assertJsonPath('data.formatted.date_time', 1770571343)
            ->assertJsonPath('data.formatted.radiant_odds_1', 10.6)
            ->assertJsonPath('data.formatted.radiant_odds_2', 10.6)
            ->assertJsonPath('data.formatted.dire_odds_1', 0)
            ->assertJsonPath('data.formatted.dire_odds_2', 0)
            ->assertJsonCount(2, 'data.minute_table')
            ->assertJsonPath('data.minute_table.0.minute', 20)
            ->assertJsonPath('data.minute_table.0.time_start', 20)
            ->assertJsonPath('data.minute_table.0.time_end', 21)
            ->assertJsonPath('data.minute_table.0.advantage_side', 'radiant')
            ->assertJsonPath('data.minute_table.0.advantage_percent', 10.6)
            ->assertJsonPath('data.minute_table.0.radiant_advantage', 10.6)
            ->assertJsonPath('data.minute_table.0.hero_base_adjustment', 10.6)
            ->assertJsonPath('data.minute_table.0.hero_tempo_adjustment', 0)
            ->assertJsonPath('data.minute_table.0.dire_advantage', 0)
            ->assertJsonPath('data.minute_table.1.minute', 21)
            ->assertJsonPath('data.minute_table.1.time_start', 20)
            ->assertJsonPath('data.minute_table.1.time_end', 22)
            ->assertJsonPath('data.minute_table.1.advantage_side', 'radiant')
            ->assertJsonPath('data.minute_table.1.advantage_percent', 10.6)
            ->assertJsonPath('data.minute_table.1.radiant_advantage', 10.6)
            ->assertJsonPath('data.minute_table.1.dire_advantage', 0)
            ->assertJsonPath('data.request.match.operationName', 'GetMatchPicksBans')
            ->assertJsonPath('data.request.match.variables.matchId', $matchId)
            ->assertJsonPath('data.request.analysis.bracket', 'IMMORTAL')
            ->assertJsonPath('data.request.analysis.bracketBasicIds', 'DIVINE_IMMORTAL')
            ->assertJsonPath('data.request.analysis.week', 1770571343)
            ->assertJsonPath('data.request.analysis.dataWindows.active.mode', 'legacy_mixed')
            ->assertJsonPath('data.request.analysis.dataWindows.active.heroes_meta_positions_weeks', 1)
            ->assertJsonPath('data.request.analysis.dataWindows.active.hero_stats_by_time_weeks', 1)
            ->assertJsonPath('data.request.analysis.dataWindows.active.synergy_weeks', 4)
            ->assertJsonCount(4, 'data.request.analysis.dataWindows.targets.4w.week_timestamps')
            ->assertJsonCount(8, 'data.request.analysis.dataWindows.targets.8w.week_timestamps')
            ->assertJsonCount(12, 'data.request.analysis.dataWindows.targets.12w.week_timestamps')
            ->assertJsonPath('data.google_sheets.sheet_title', 'BLAST SLAM VI')
            ->assertJsonPath('data.google_sheets.row', 8)
            ->assertJsonPath('data.google_sheets.cells.A8', '8683333901')
            ->assertJsonPath('data.google_sheets.cells.C8', 'Team Liquid')
            ->assertJsonPath('data.google_sheets.cells.D8', 'GamerLegion')
            ->assertJsonPath('data.google_sheets.cells.E8', 'Radiant')
            ->assertJsonPath('data.google_sheets.cells.F8', '10,6%')
            ->assertJsonPath('data.google_sheets.cells.G8', '10,6%')
            ->assertJsonPath('data.google_sheets.cells.I8', '0,0%')
            ->assertJsonPath('data.google_sheets.cells.J8', '0,0%')
            ->assertJsonPath('data.snapshot_dataset.sheet_title', 'Dataset')
            ->assertJsonPath('data.snapshot_dataset.exported_rows', 1)
            ->assertJsonPath('data.raw.match.id', $matchId)
            ->assertJsonPath('data.raw.analysis_summary.hero_stats_by_time_bracket.heroStatsByTime_1.count', 4)
            ->assertJsonPath('data.raw.analysis_summary.synergy.matchUp_Prev_Week_1.count', 0)
            ->assertJsonPath('data.raw.analysis_summary.stratz_data_windows.active.mode', 'legacy_mixed')
            ->assertJsonMissingPath('data.raw.analysis');

        $snapshot = DraftSnapshot::query()->firstOrFail();

        $this->assertSame('match_id', $snapshot->source);
        $this->assertSame($matchId, $snapshot->match_id);
        $this->assertSame('Team Liquid', $snapshot->radiant_team);
        $this->assertSame('GamerLegion', $snapshot->dire_team);
        $this->assertSame([114, 25, 23, 79, 112], $snapshot->radiant_heroes);
        $this->assertSame([70, 59, 39, 83, 37], $snapshot->dire_heroes);
        $this->assertSame('dematus-rosh-v1', $snapshot->model_version);
        $this->assertSame($matchId, data_get($snapshot->draft_payload, 'match_id'));
        $this->assertSame('BLAST SLAM VI', data_get($snapshot->sheet_payload, 'sheet_title'));
        $this->assertSame('GetMatchPicksBans', data_get($snapshot->stratz_payload, 'request.match.operationName'));
        $this->assertSame('legacy_mixed', data_get($snapshot->stratz_payload, 'request.analysis.dataWindows.active.mode'));
        $this->assertSame([1770571343, 1769966543, 1769361743, 1768756943], data_get($snapshot->stratz_payload, 'request.analysis.dataWindows.targets.4w.week_timestamps'));
        $this->assertIsArray(data_get($snapshot->stratz_payload, 'analysis.heroes_meta_positions'));
        $this->assertSame('legacy_mixed', data_get($snapshot->feature_payload, 'analysis_summary.stratz_data_windows.active.mode'));
        $this->assertSame(10.6, data_get($snapshot->feature_payload, 'formatted.radiant_odds_1'));
        $this->assertCount(12, $snapshot->stratzDataBuckets);
        $this->assertSame(
            [
                'hero_stats_by_time_bracket' => 12,
                'heroes_meta_positions' => 12,
                'synergy' => 12,
            ],
            $snapshot->stratzDataBuckets
                ->sortBy('data_type')
                ->mapWithKeys(fn ($bucket): array => [$bucket->data_type => $bucket->window_weeks])
                ->all(),
        );
        $this->assertSame(
            [1770571343, 1769966543, 1769361743, 1768756943],
            $snapshot->stratzDataBuckets->firstWhere('data_type', 'synergy')?->week_timestamps,
        );

        Http::assertSentCount(17);

        Http::assertSent(function (Request $request) use ($matchId): bool {
            if (! $this->isStratzGraphqlRequest($request)) {
                return false;
            }

            return str_contains((string) $request['query'], 'query GetMatchPicksBans')
                && str_contains((string) $request['query'], 'isRadiant')
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('Content-Type', 'application/json')
                && $request->hasHeader('User-Agent', 'STRATZ_API')
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request->hasHeader('GraphQL-Require-Preflight', '1')
                && ! $request->hasHeader('Origin')
                && ! $request->hasHeader('Referer')
                && $request['variables']['matchId'] === $matchId;
        });

        Http::assertSent(function (Request $request): bool {
            if (! $this->isStratzGraphqlRequest($request)) {
                return false;
            }

            return str_contains((string) $request['query'], 'query HeroesMetaPositionsByWeeks')
                && $request['variables']['bracketBasicIds'] === 'DIVINE_IMMORTAL'
                && str_contains((string) $request['query'], 'week: 1770571343')
                && $request['variables']['heroIds'] === [114, 25, 23, 79, 112, 70, 59, 39, 83, 37];
        });

        Http::assertSent(function (Request $request): bool {
            if (! $this->isStratzGraphqlRequest($request)) {
                return false;
            }

            if (! str_contains((string) $request['query'], 'query GetHeroStatsByTimeForWeeks')) {
                return false;
            }

            return $request['variables']['bracketBasicIds'] === 'DIVINE_IMMORTAL'
                && $request['variables']['heroIds'] === [114, 25, 23, 79, 112, 70, 59, 39, 83, 37]
                && str_contains((string) $request['query'], 'week: 1770571343')
                && str_contains((string) $request['query'], 'maxTime: 62');
        });

        Http::assertSent(function (Request $request): bool {
            if (! $this->isStratzGraphqlRequest($request)) {
                return false;
            }

            return str_contains((string) $request['query'], 'query SynergyForWeeks')
                && $request['variables']['bracketBasicIds'] === 'DIVINE_IMMORTAL'
                && $request['variables']['matchLimit'] === 0
                && $request['variables']['take'] === 200
                && str_contains((string) $request['query'], 'week: 1770571343')
                && str_contains((string) $request['query'], 'week: 1769966543')
                && str_contains((string) $request['query'], 'week: 1769361743')
                && str_contains((string) $request['query'], 'week: 1768756943')
                && $request['variables']['heroIds'] === [114, 25, 23, 79, 112, 70, 59, 39, 83, 37];
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
            return str_contains(rawurldecode($request->url()), "/values/'BLAST SLAM VI'!A:A")
                && $request->hasHeader('Authorization', 'Bearer google-access-token');
        });

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id/values:batchUpdate')
                && $request->hasHeader('Authorization', 'Bearer google-access-token')
                && $request['valueInputOption'] === 'USER_ENTERED'
                && $request['data'] === [
                    [
                        'range' => "'BLAST SLAM VI'!A8",
                        'majorDimension' => 'ROWS',
                        'values' => [['8683333901']],
                    ],
                    [
                        'range' => "'BLAST SLAM VI'!C8:E8",
                        'majorDimension' => 'ROWS',
                        'values' => [['Team Liquid', 'GamerLegion', 'Radiant']],
                    ],
                    [
                        'range' => "'BLAST SLAM VI'!F8:G8",
                        'majorDimension' => 'ROWS',
                        'values' => [['10,6%', '10,6%']],
                    ],
                    [
                        'range' => "'BLAST SLAM VI'!I8:J8",
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
        config()->set('services.google_sheets.dataset_sheet', 'BLAST SLAM VI');

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

            if (
                str_contains(rawurldecode($request->url()), "/values/'BLAST SLAM VI'!A:")
                && ! str_contains(rawurldecode($request->url()), "/values/'BLAST SLAM VI'!A:A")
            ) {
                return Http::response(['values' => []]);
            }

            if (str_contains(rawurldecode($request->url()), "/values/'BLAST SLAM VI'!A:A")) {
                return Http::response([
                    'range' => "'BLAST SLAM VI'!A:A",
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

            if (str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id:batchUpdate')) {
                return Http::response([
                    'replies' => [],
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
                        ['range' => "'BLAST SLAM VI'!A9", 'values' => [['9999999999']]],
                        ['range' => "'BLAST SLAM VI'!C9", 'values' => [['Team Liquid']]],
                        ['range' => "'BLAST SLAM VI'!D9", 'values' => [['GamerLegion']]],
                        ['range' => "'BLAST SLAM VI'!E9", 'values' => [['Radiant']]],
                        ['range' => "'BLAST SLAM VI'!F9", 'values' => [['10,6%']]],
                        ['range' => "'BLAST SLAM VI'!G9", 'values' => [['10,6%']]],
                        ['range' => "'BLAST SLAM VI'!I9", 'values' => [['0,0%']]],
                        ['range' => "'BLAST SLAM VI'!J9", 'values' => [['0,0%']]],
                    ],
                ]);
            }

            if (str_contains($request->url(), 'https://sheets.googleapis.com/')) {
                return Http::response([]);
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
            ->assertJsonPath('data.google_sheets.cells.A9', '9999999999')
            ->assertJsonPath('data.google_sheets.cells.C9', 'Team Liquid')
            ->assertJsonPath('data.google_sheets.cells.D9', 'GamerLegion')
            ->assertJsonPath('data.google_sheets.cells.E9', 'Radiant')
            ->assertJsonPath('data.google_sheets.cells.F9', '10,6%')
            ->assertJsonPath('data.google_sheets.cells.G9', '10,6%')
            ->assertJsonPath('data.google_sheets.cells.I9', '0,0%')
            ->assertJsonPath('data.google_sheets.cells.J9', '0,0%');

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id/values:batchUpdate')
                && $request->hasHeader('Authorization', 'Bearer google-access-token')
                && $request['data'] === [
                    [
                        'range' => "'BLAST SLAM VI'!A9",
                        'majorDimension' => 'ROWS',
                        'values' => [['9999999999']],
                    ],
                    [
                        'range' => "'BLAST SLAM VI'!C9:E9",
                        'majorDimension' => 'ROWS',
                        'values' => [['Team Liquid', 'GamerLegion', 'Radiant']],
                    ],
                    [
                        'range' => "'BLAST SLAM VI'!F9:G9",
                        'majorDimension' => 'ROWS',
                        'values' => [['10,6%', '10,6%']],
                    ],
                    [
                        'range' => "'BLAST SLAM VI'!I9:J9",
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

    public function test_rosh_html_request_parses_dltv_html_and_runs_hero_based_analysis(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-25 12:00:00 UTC'));

        config()->set('services.stratz.token', 'test-token');
        config()->set('services.google_sheets.spreadsheet_url', null);
        config()->set('services.google_sheets.service_account_credentials', null);

        $week = now()->timestamp;
        $picks = $this->roshPicks();
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

        Http::fake(function (Request $request) use ($metaPositions, $globalTimeStats, $bracketTimeStats) {
            $query = $request->offsetExists('query')
                ? (string) $request['query']
                : '';

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

        $response = $this
            ->withSession([config('static-auth.session_key') => true])
            ->postJson(route('stratz.rosh-html'), [
                'html' => $this->fakeDltvDraftHtml(),
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('type', 'rosh')
            ->assertJsonPath('data.formatted.match_id', 'LIVE')
            ->assertJsonPath('data.formatted.radiant_team', 'Team Liquid')
            ->assertJsonPath('data.formatted.dire_team', 'GamerLegion')
            ->assertJsonPath('data.formatted.radiant_odds_1', 10.6)
            ->assertJsonPath('data.formatted.radiant_odds_2', 10.6)
            ->assertJsonPath('data.request.input.mode', 'heroes')
            ->assertJsonPath('data.request.input.radiantHeroes.0', 114)
            ->assertJsonPath('data.request.input.direHeroes.4', 37)
            ->assertJsonPath('data.request.analysis.week', $week)
            ->assertJsonPath('data.parsed_html.radiant_team', 'Team Liquid')
            ->assertJsonPath('data.parsed_html.dire_team', 'GamerLegion')
            ->assertJsonPath('data.parsed_html.radiant_heroes.3', 79)
            ->assertJsonPath('data.parsed_html.dire_heroes.1', 59);

        Http::assertSent(function (Request $request): bool {
            return $this->isStratzGraphqlRequest($request)
                && str_contains((string) $request['query'], 'query HeroesMetaPositionsByWeek');
        });

        Http::assertNotSent(function (Request $request): bool {
            return $this->isStratzGraphqlRequest($request)
                && str_contains((string) $request['query'], 'query GetMatchPicksBans');
        });

        Carbon::setTestNow();
    }

    public function test_rosh_gist_request_downloads_gist_html_and_runs_hero_based_analysis(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-25 12:00:00 UTC'));

        config()->set('services.stratz.token', 'test-token');
        config()->set('services.google_sheets.spreadsheet_url', null);
        config()->set('services.google_sheets.service_account_credentials', null);
        config()->set('services.dltv.gist_url', 'https://gist.github.com/upndrey/a45ca575e6eeaed23b81240b03707b17');

        $week = now()->timestamp;
        $picks = $this->roshPicks();
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

        Http::fake(function (Request $request) use ($metaPositions, $globalTimeStats, $bracketTimeStats) {
            if ($request->url() === 'https://api.github.com/gists/a45ca575e6eeaed23b81240b03707b17') {
                return Http::response([
                    'files' => [
                        'temp.html' => [
                            'filename' => 'temp.html',
                            'raw_url' => 'https://gist.githubusercontent.com/upndrey/a45ca575e6eeaed23b81240b03707b17/raw/temp.html',
                        ],
                    ],
                ]);
            }

            if ($request->url() === 'https://gist.githubusercontent.com/upndrey/a45ca575e6eeaed23b81240b03707b17/raw/temp.html') {
                return Http::response($this->fakeDltvDraftHtml(), 200, [
                    'Content-Type' => 'text/html',
                ]);
            }

            $query = $request->offsetExists('query')
                ? (string) $request['query']
                : '';

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

        $response = $this->postJson(route('stratz.rosh-gist'));

        $response
            ->assertOk()
            ->assertJsonPath('type', 'rosh')
            ->assertJsonPath('data.formatted.match_id', 'LIVE')
            ->assertJsonPath('data.formatted.radiant_team', 'Team Liquid')
            ->assertJsonPath('data.formatted.dire_team', 'GamerLegion')
            ->assertJsonPath('data.request.input.radiantHeroes.0', 114)
            ->assertJsonPath('data.request.input.direHeroes.4', 37)
            ->assertJsonPath('data.request.analysis.week', $week)
            ->assertJsonPath('data.parsed_html.radiant_heroes.3', 79)
            ->assertJsonPath('data.source.type', 'gist')
            ->assertJsonPath('data.source.url', 'https://gist.github.com/upndrey/a45ca575e6eeaed23b81240b03707b17');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.github.com/gists/a45ca575e6eeaed23b81240b03707b17';
        });

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://gist.githubusercontent.com/upndrey/a45ca575e6eeaed23b81240b03707b17/raw/temp.html';
        });

        Carbon::setTestNow();
    }

    public function test_rosh_heroes_request_builds_analysis_from_hero_list_and_appends_live_row(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-25 12:00:00 UTC'));

        config()->set('services.stratz.token', 'test-token');
        config()->set('services.google_sheets.spreadsheet_url', 'https://docs.google.com/spreadsheets/d/test-sheet-id/edit?gid=0');
        config()->set('services.google_sheets.service_account_credentials', $this->fakeGoogleCredentialsPath());
        config()->set('services.google_sheets.timeout', 20);
        config()->set('services.google_sheets.dataset_sheet', 'BLAST SLAM VI');

        $week = now()->timestamp;
        $picks = $this->roshPicks();
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

        Http::fake(function (Request $request) use ($metaPositions, $globalTimeStats, $bracketTimeStats) {
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

            if (str_contains(rawurldecode($request->url()), "/values/'BLAST SLAM VI'!A:A")) {
                return Http::response([
                    'range' => "'BLAST SLAM VI'!A:A",
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
                        ['range' => "'BLAST SLAM VI'!A9", 'values' => [['LIVE']]],
                        ['range' => "'BLAST SLAM VI'!C9", 'values' => [['Team Liquid']]],
                        ['range' => "'BLAST SLAM VI'!D9", 'values' => [['GamerLegion']]],
                        ['range' => "'BLAST SLAM VI'!E9", 'values' => [['Radiant']]],
                        ['range' => "'BLAST SLAM VI'!F9", 'values' => [['10,6%']]],
                        ['range' => "'BLAST SLAM VI'!G9", 'values' => [['10,6%']]],
                        ['range' => "'BLAST SLAM VI'!I9", 'values' => [['0,0%']]],
                        ['range' => "'BLAST SLAM VI'!J9", 'values' => [['0,0%']]],
                    ],
                ]);
            }

            if (str_contains($request->url(), 'https://sheets.googleapis.com/')) {
                return Http::response([]);
            }

            $query = $request->offsetExists('query')
                ? (string) $request['query']
                : '';

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

        $response = $this->postJson(route('stratz.rosh-heroes'), [
            'radiant_team' => 'Team Liquid',
            'dire_team' => 'GamerLegion',
            'radiant_heroes' => [114, 25, 23, 79, 112],
            'dire_heroes' => [70, 59, 39, 83, 37],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('type', 'rosh')
            ->assertJsonPath('data.formatted.match_id', 'LIVE')
            ->assertJsonPath('data.formatted.winner', 'radiant')
            ->assertJsonPath('data.formatted.radiant_team', 'Team Liquid')
            ->assertJsonPath('data.formatted.dire_team', 'GamerLegion')
            ->assertJsonPath('data.formatted.bracket', 'IMMORTAL')
            ->assertJsonPath('data.formatted.bracket_basic', 'DIVINE_IMMORTAL')
            ->assertJsonPath('data.formatted.date_time', $week)
            ->assertJsonPath('data.formatted.radiant_odds_1', 10.6)
            ->assertJsonPath('data.formatted.radiant_odds_2', 10.6)
            ->assertJsonPath('data.formatted.dire_odds_1', 0)
            ->assertJsonPath('data.formatted.dire_odds_2', 0)
            ->assertJsonPath('data.request.input.mode', 'heroes')
            ->assertJsonPath('data.request.input.matchId', 'LIVE')
            ->assertJsonPath('data.request.input.radiantTeam', 'Team Liquid')
            ->assertJsonPath('data.request.input.direTeam', 'GamerLegion')
            ->assertJsonPath('data.request.input.radiantHeroes.0', 114)
            ->assertJsonPath('data.request.input.direHeroes.4', 37)
            ->assertJsonPath('data.request.analysis.week', $week)
            ->assertJsonPath('data.google_sheets.row', 9)
            ->assertJsonPath('data.google_sheets.cells.A9', 'LIVE')
            ->assertJsonPath('data.google_sheets.cells.C9', 'Team Liquid')
            ->assertJsonPath('data.google_sheets.cells.D9', 'GamerLegion')
            ->assertJsonPath('data.google_sheets.cells.E9', 'Radiant')
            ->assertJsonPath('data.google_sheets.cells.F9', '10,6%')
            ->assertJsonPath('data.google_sheets.cells.G9', '10,6%')
            ->assertJsonPath('data.google_sheets.cells.I9', '0,0%')
            ->assertJsonPath('data.google_sheets.cells.J9', '0,0%')
            ->assertJsonPath('data.raw.match.id', 'LIVE')
            ->assertJsonPath('data.raw.match.players.0.heroId', 114)
            ->assertJsonPath('data.raw.match.players.9.heroId', 37);

        Http::assertSent(function (Request $request) use ($week): bool {
            if (! $this->isStratzGraphqlRequest($request)) {
                return false;
            }

            return str_contains((string) $request['query'], 'query HeroesMetaPositionsByWeek')
                && $request['variables']['bracketBasicIds'] === 'DIVINE_IMMORTAL'
                && str_contains((string) $request['query'], "week: {$week}");
        });

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id/values:batchUpdate')
                && $request->hasHeader('Authorization', 'Bearer google-access-token')
                && $request['data'] === [
                    [
                        'range' => "'BLAST SLAM VI'!A9",
                        'majorDimension' => 'ROWS',
                        'values' => [['LIVE']],
                    ],
                    [
                        'range' => "'BLAST SLAM VI'!C9:E9",
                        'majorDimension' => 'ROWS',
                        'values' => [['Team Liquid', 'GamerLegion', 'Radiant']],
                    ],
                    [
                        'range' => "'BLAST SLAM VI'!F9:G9",
                        'majorDimension' => 'ROWS',
                        'values' => [['10,6%', '10,6%']],
                    ],
                    [
                        'range' => "'BLAST SLAM VI'!I9:J9",
                        'majorDimension' => 'ROWS',
                        'values' => [['0,0%', '0,0%']],
                    ],
                ];
        });

        Http::assertNotSent(function (Request $request): bool {
            return $this->isStratzGraphqlRequest($request)
                && str_contains((string) $request['query'], 'query GetMatchPicksBans');
        });

        Http::assertNotSent(function (Request $request): bool {
            return $this->isStratzGraphqlRequest($request)
                && str_contains((string) $request['query'], 'query PlayerHeroHighlights');
        });

        Carbon::setTestNow();
    }

    public function test_rosh_heroes_counts_bidirectional_synergy_signals_once(): void
    {
        $rosh = $this->calculateLiveRoshWithSynergy([
            'matchUp_Prev_Week_1' => [
                [
                    'heroId' => 114,
                    'with' => [['heroId2' => 25, 'matchCount' => 100, 'synergy' => 4.0]],
                    'vs' => [['heroId2' => 70, 'matchCount' => 100, 'synergy' => 3.0]],
                ],
                [
                    'heroId' => 25,
                    'with' => [['heroId2' => 114, 'matchCount' => 100, 'synergy' => 4.0]],
                    'vs' => [],
                ],
                [
                    'heroId' => 70,
                    'with' => [],
                    'vs' => [['heroId2' => 114, 'matchCount' => 100, 'synergy' => -3.0]],
                ],
            ],
        ]);

        $this->assertSame(0.0, $rosh['minute_table'][0]['hero_adjustment']);
        $this->assertSame(7.0, $rosh['minute_table'][0]['synergy_adjustment']);
        $this->assertSame(7.0, $rosh['minute_table'][0]['win_rate_graph']);
    }

    public function test_rosh_heroes_reduces_synergy_from_small_match_samples(): void
    {
        $rosh = $this->calculateLiveRoshWithSynergy([
            'matchUp_Prev_Week_1' => [
                [
                    'heroId' => 114,
                    'with' => [['heroId2' => 25, 'matchCount' => 20, 'synergy' => 10.0]],
                    'vs' => [],
                ],
                [
                    'heroId' => 25,
                    'with' => [['heroId2' => 114, 'matchCount' => 20, 'synergy' => 10.0]],
                    'vs' => [],
                ],
            ],
        ]);

        $this->assertSame(2.0, $rosh['minute_table'][0]['synergy_adjustment']);
        $this->assertSame(2.0, $rosh['minute_table'][0]['win_rate_graph']);
    }

    public function test_rosh_heroes_caps_total_synergy_adjustment(): void
    {
        $rosh = $this->calculateLiveRoshWithSynergy([
            'matchUp_Prev_Week_1' => [
                [
                    'heroId' => 114,
                    'with' => [['heroId2' => 25, 'matchCount' => 100, 'synergy' => 50.0]],
                    'vs' => [],
                ],
                [
                    'heroId' => 25,
                    'with' => [['heroId2' => 114, 'matchCount' => 100, 'synergy' => 50.0]],
                    'vs' => [],
                ],
            ],
        ]);

        $this->assertSame(30.0, $rosh['minute_table'][0]['synergy_adjustment']);
        $this->assertSame(30.0, $rosh['minute_table'][0]['win_rate_graph']);
    }

    public function test_rosh_heroes_smooths_low_sample_immortal_time_stats_without_global_fallback(): void
    {
        $picks = array_map(
            static fn (array $pick): array => [
                ...$pick,
                'baseDiff' => 0.0,
            ],
            $this->roshPicks(),
        );
        $immortalTimeStats = $this->fakeRoshHeroStatsByTime(
            $picks,
            [114 => 40.0],
            [114 => [20 => 1800, 21 => 900]],
        );

        $rosh = $this->calculateLiveRoshWithSynergy([], $immortalTimeStats);

        $this->assertSame(4.4, $rosh['minute_table'][0]['hero_adjustment']);
        $this->assertSame(4.4, $rosh['minute_table'][1]['hero_adjustment']);
        $this->assertSame(0.0, $rosh['minute_table'][0]['hero_base_adjustment']);
        $this->assertSame(4.4, $rosh['minute_table'][0]['hero_tempo_adjustment']);

        Http::assertNotSent(function (Request $request): bool {
            return str_contains((string) $request['query'], 'query GetHeroStatsByTime')
                && ! isset($request['variables']['bracketBasicIds']);
        });
    }

    public function test_rosh_heroes_uses_following_time_buckets_to_compute_minute_sixty_tempo_adjustment(): void
    {
        $picks = array_map(
            static fn (array $pick): array => [
                ...$pick,
                'baseDiff' => 0.0,
            ],
            $this->roshPicks(),
        );
        $immortalTimeStats = $this->fakeRoshHeroStatsByTime($picks);
        $immortalTimeStats['heroStatsByTime_1'] = [
            ['heroId' => 114, 'positionIds' => ['POSITION_1'], 'time' => 59, 'matchCount' => 400, 'winCount' => 300],
            ['heroId' => 114, 'positionIds' => ['POSITION_1'], 'time' => 60, 'matchCount' => 300, 'winCount' => 225],
            ['heroId' => 114, 'positionIds' => ['POSITION_1'], 'time' => 61, 'matchCount' => 200, 'winCount' => 150],
            ['heroId' => 114, 'positionIds' => ['POSITION_1'], 'time' => 62, 'matchCount' => 100, 'winCount' => 75],
        ];

        $rosh = $this->calculateLiveRoshWithSynergy([], $immortalTimeStats);
        $minuteSixty = collect($rosh['minute_table'])->firstWhere('minute', 60);

        $this->assertIsArray($minuteSixty);
        $this->assertSame(1.3, $minuteSixty['hero_tempo_adjustment']);
        $this->assertSame(1.3, $minuteSixty['hero_adjustment']);
    }

    public function test_dltv_extension_payload_runs_full_rosh_calculation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-25 12:00:00 UTC'));

        config()->set('services.stratz.token', 'test-token');
        config()->set('services.dltv.extension_token', 'extension-secret');
        config()->set('services.google_sheets.spreadsheet_url', null);
        config()->set('services.google_sheets.service_account_credentials', null);

        $picks = $this->roshPicks();
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

        Http::fake(function (Request $request) use ($metaPositions, $globalTimeStats, $bracketTimeStats) {
            $query = $request->offsetExists('query')
                ? (string) $request['query']
                : '';

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

        $response = $this->postJson(route('dltv-match.rosh', ['token' => 'extension-secret']), $this->fakeDltvExtensionPayload());

        $response
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertJsonPath('type', 'rosh')
            ->assertJsonPath('data.formatted.radiant_team', 'Team Liquid')
            ->assertJsonPath('data.formatted.dire_team', 'GamerLegion')
            ->assertJsonPath('data.formatted.radiant_odds_1', 10.6)
            ->assertJsonPath('data.formatted.radiant_odds_2', 10.6)
            ->assertJsonPath('data.formatted.dire_odds_1', 0)
            ->assertJsonPath('data.formatted.dire_odds_2', 0)
            ->assertJsonPath('data.parsed_extension_rosh_payload.radiant_heroes', [114, 25, 23, 79, 112])
            ->assertJsonPath('data.parsed_extension_rosh_payload.dire_heroes', [70, 59, 39, 83, 37])
            ->assertJsonPath('data.source.type', 'dltv-browser-extension')
            ->assertJsonPath('data.source.page_url', 'https://ru.dltv.org/matches/123-team-liquid-vs-gamerlegion');

        $snapshot = DraftSnapshot::query()->firstOrFail();

        $this->assertSame('live_dltv', $snapshot->source);
        $this->assertNull($snapshot->match_id);
        $this->assertSame('https://ru.dltv.org/matches/123-team-liquid-vs-gamerlegion', $snapshot->dltv_url);
        $this->assertSame('Team Liquid', $snapshot->radiant_team);
        $this->assertSame('GamerLegion', $snapshot->dire_team);
        $this->assertSame([114, 25, 23, 79, 112], $snapshot->radiant_heroes);
        $this->assertSame([70, 59, 39, 83, 37], $snapshot->dire_heroes);
        $this->assertSame('https://ru.dltv.org/matches/123-team-liquid-vs-gamerlegion', data_get($snapshot->draft_payload, 'page_url'));
        $this->assertNull($snapshot->sheet_payload);
        $this->assertSame(10.6, data_get($snapshot->feature_payload, 'formatted.radiant_odds_1'));
        $this->assertIsArray(data_get($snapshot->stratz_payload, 'analysis.heroes_meta_positions'));

        Http::assertSentCount(6);

        Carbon::setTestNow();
    }

    public function test_dltv_extension_payload_requires_token(): void
    {
        config()->set('services.dltv.extension_token', 'extension-secret');

        $response = $this->postJson(route('dltv-match.rosh'), $this->fakeDltvExtensionPayload());

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }

    public function test_dltv_extension_payload_returns_external_http_response_details(): void
    {
        config()->set('services.stratz.token', 'test-token');
        config()->set('services.stratz.endpoint', 'https://api.stratz.com/graphql');
        config()->set('services.dltv.extension_token', 'extension-secret');
        config()->set('services.google_sheets.spreadsheet_url', null);
        config()->set('services.google_sheets.service_account_credentials', null);

        Http::fake([
            'https://api.stratz.com/graphql*' => Http::response(
                '<!DOCTYPE html><html class="no-js oldie" lang="en-US"><body>Forbidden</body></html>',
                403,
                ['cf-ray' => 'test-ray', 'set-cookie' => 'secret-cookie'],
            ),
        ]);

        $response = $this->postJson(route('dltv-match.rosh', ['token' => 'extension-secret']), $this->fakeDltvExtensionPayload());

        $response
            ->assertStatus(422)
            ->assertJsonPath('external_response.service', 'stratz')
            ->assertJsonPath('external_response.status', 403)
            ->assertJsonPath('external_response.url', 'https://api.stratz.com/graphql')
            ->assertJsonPath('external_response.request_headers.User-Agent.0', 'STRATZ_API')
            ->assertJsonPath('external_response.request_headers.Authorization.0', '[redacted]')
            ->assertJsonPath('external_response.request_headers.GraphQL-Require-Preflight.0', '1')
            ->assertJsonMissingPath('external_response.request_headers.Origin')
            ->assertJsonMissingPath('external_response.request_headers.Referer')
            ->assertJsonPath('external_response.headers.cf-ray.0', 'test-ray')
            ->assertJsonMissingPath('external_response.headers.set-cookie')
            ->assertJsonPath('external_response.body', '<!DOCTYPE html><html class="no-js oldie" lang="en-US"><body>Forbidden</body></html>');
    }

    public function test_stratz_request_can_force_ipv4(): void
    {
        if (! defined('CURLOPT_IPRESOLVE') || ! defined('CURL_IPRESOLVE_V4')) {
            $this->markTestSkipped('The PHP cURL constants needed to force IPv4 are not available.');
        }

        config()->set('services.stratz.token', 'test-token');
        config()->set('services.stratz.force_ipv4', true);
        config()->set('services.google_sheets.spreadsheet_url', null);
        config()->set('services.google_sheets.service_account_credentials', null);

        $picks = $this->roshPicks();
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

        Http::fake(function (Request $request) use ($metaPositions, $globalTimeStats, $bracketTimeStats) {
            $query = $request->offsetExists('query')
                ? (string) $request['query']
                : '';

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

        $response = $this
            ->withSession([config('static-auth.session_key') => true])
            ->postJson(route('stratz.rosh-heroes'), [
                'radiant_team' => 'Team Liquid',
                'dire_team' => 'GamerLegion',
                'radiant_heroes' => [114, 25, 23, 79, 112],
                'dire_heroes' => [70, 59, 39, 83, 37],
            ]);

        $response->assertOk();

        Http::assertSent(fn (Request $request): bool => $this->isStratzGraphqlRequest($request));
    }

    public function test_rosh_heroes_request_requires_full_payload(): void
    {
        $response = $this->postJson(route('stratz.rosh-heroes'), [
            'radiant_team' => 'Radiant',
            'dire_team' => 'Dire',
            'radiant_heroes' => [1, 2, 3],
            'dire_heroes' => [4, 5, 6, 7, 8],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['radiant_heroes']);
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
                'isRadiant' => $pick['isRadiant'],
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
            'startDateTime' => 1770571343,
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

    /**
     * @param  array<string, mixed>  $synergy
     * @return array<string, mixed>
     */
    private function calculateLiveRoshWithSynergy(array $synergy, ?array $timeStats = null): array
    {
        config()->set('services.stratz.token', 'test-token');

        $picks = array_map(
            static fn (array $pick): array => [
                ...$pick,
                'baseDiff' => 0.0,
            ],
            $this->roshPicks(),
        );
        $metaPositions = $this->fakeRoshMetaPositions($picks);
        $timeStats ??= $this->fakeRoshHeroStatsByTime($picks);
        $synergy = array_merge([
            'matchUp_Prev_Week_1' => [],
            'matchUp_Prev_Week_2' => [],
            'matchUp_Prev_Week_3' => [],
            'matchUp_Prev_Week_4' => [],
        ], $synergy);

        Http::fake(function (Request $request) use ($metaPositions, $timeStats, $synergy) {
            $query = $request->offsetExists('query')
                ? (string) $request['query']
                : '';

            if (str_contains($query, 'query HeroesMetaPositionsByWeek')) {
                return Http::response([
                    'data' => [
                        'heroStats' => $metaPositions,
                    ],
                ]);
            }

            if (str_contains($query, 'query GetHeroStatsByTime')) {
                return Http::response([
                    'data' => [
                        'heroStats' => $timeStats,
                    ],
                ]);
            }

            if (str_contains($query, 'query Synergy')) {
                return Http::response([
                    'data' => [
                        'heroStats' => $synergy,
                    ],
                ]);
            }

            return Http::response([], 500);
        });

        return app(StratzService::class)->getRoshFromHeroes([
            'radiant_team' => 'Radiant',
            'dire_team' => 'Dire',
            'radiant_heroes' => [114, 25, 23, 79, 112],
            'dire_heroes' => [70, 59, 39, 83, 37],
        ]);
    }

    /**
     * @return array<string, int|float>
     * @return array<string, mixed>
     */
    private function fakeDltvDraftHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head><title>Team Liquid vs GamerLegion - DLTV</title></head>
<body>
<script>
let series_item = {
    "first_team": {"id": 2163, "title": "Team Liquid"},
    "second_team": {"id": 9964962, "title": "GamerLegion"},
    "maps": [{
        "radiant_team_id": 2163,
        "dire_team_id": 9964962,
        "radiant_picks": [
            {"hero_id": 79, "role": 4},
            {"hero_id": 114, "role": 1},
            {"hero_id": 112, "role": 5},
            {"hero_id": 25, "role": 2},
            {"hero_id": 23, "role": 3}
        ],
        "dire_picks": [
            {"hero_id": 39, "role": 3},
            {"hero_id": 37, "role": 5},
            {"hero_id": 70, "role": 1},
            {"hero_id": 83, "role": 4},
            {"hero_id": 59, "role": 2}
        ]
    }]
};
</script>
</body>
</html>
HTML;
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeDltvExtensionPayload(): array
    {
        $radiantPlayers = [
            ['player_name' => 'miCKe', 'hero_name' => 'Monkey King', 'role_id' => 1],
            ['player_name' => 'Nisha', 'hero_name' => 'Lina', 'role_id' => 2],
            ['player_name' => 'SaberLight', 'hero_name' => 'Kunkka', 'role_id' => 3],
            ['player_name' => 'Boxi', 'hero_name' => 'Shadow Demon', 'role_id' => 4],
            ['player_name' => 'Insania', 'hero_name' => 'Winter Wyvern', 'role_id' => 5],
        ];
        $direPlayers = [
            ['player_name' => 'watson', 'hero_name' => 'Ursa', 'role_id' => 1],
            ['player_name' => 'Quinn', 'hero_name' => 'Huskar', 'role_id' => 2],
            ['player_name' => 'Ace', 'hero_name' => 'Queen of Pain', 'role_id' => 3],
            ['player_name' => 'tOfu', 'hero_name' => 'Treant Protector', 'role_id' => 4],
            ['player_name' => 'Seleri', 'hero_name' => 'Warlock', 'role_id' => 5],
        ];

        $players = [
            ...array_map(
                static fn (array $player): array => [
                    ...$player,
                    'team_index' => 1,
                    'player_url' => null,
                    'hero_image_url' => null,
                    'role_name' => null,
                    'level' => 20,
                ],
                $radiantPlayers,
            ),
            ...array_map(
                static fn (array $player): array => [
                    ...$player,
                    'team_index' => 2,
                    'player_url' => null,
                    'hero_image_url' => null,
                    'role_name' => null,
                    'level' => 20,
                ],
                $direPlayers,
            ),
        ];

        return [
            'source' => 'dltv',
            'page_url' => 'https://ru.dltv.org/matches/123-team-liquid-vs-gamerlegion',
            'captured_at' => '2026-04-29T12:00:00.000Z',
            'match' => [
                'team_1' => 'Team Liquid',
                'team_2' => 'GamerLegion',
                'radiant_team' => null,
                'dire_team' => null,
                'map_number' => 1,
                'game_time' => '20:00',
                'score' => '0-0',
            ],
            'players' => $players,
            'teams' => [
                [
                    'team_index' => 1,
                    'team_name' => 'Team Liquid',
                    'players' => array_slice($players, 0, 5),
                ],
                [
                    'team_index' => 2,
                    'team_name' => 'GamerLegion',
                    'players' => array_slice($players, 5, 5),
                ],
            ],
            'meta' => [
                'players_count' => 10,
                'heroes_count' => 10,
                'roles_count' => 10,
                'warnings' => [],
            ],
        ];
    }

    private function isStratzGraphqlRequest(Request $request): bool
    {
        return $request->url() === 'https://api.stratz.com/graphql';
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
