<?php

namespace Tests\Feature;

use App\Models\DraftSnapshot;
use App\Models\StratzDataBucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SnapshotDatasetExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exports_snapshot_dataset_to_google_sheets(): void
    {
        config()->set('services.google_sheets.spreadsheet_url', 'https://docs.google.com/spreadsheets/d/test-sheet-id/edit?gid=673477564');
        config()->set('services.google_sheets.service_account_credentials', $this->fakeGoogleCredentialsPath());
        config()->set('services.google_sheets.timeout', 20);

        $snapshot = DraftSnapshot::factory()->create([
            'source' => 'match_id',
            'match_id' => 8893106015,
            'radiant_team' => 'Virtus.pro',
            'dire_team' => 'OG',
            'feature_payload' => [
                'formatted' => [
                    'radiant_odds_1' => 12.5,
                    'radiant_odds_2' => 10.5,
                    'dire_odds_1' => 0.0,
                    'dire_odds_2' => 2.0,
                ],
            ],
            'sheet_payload' => [
                'sheet_title' => 'Dematus 3.0',
                'row' => 9,
            ],
            'result_payload' => [
                'winner' => 'radiant',
            ],
        ]);

        foreach (['heroes_meta_positions', 'hero_stats_by_time_bracket', 'synergy'] as $dataType) {
            foreach ([4, 8, 12] as $windowWeeks) {
                StratzDataBucket::factory()->create([
                    'draft_snapshot_id' => $snapshot->id,
                    'bucket_key' => "stratz_window_collector:match_id:{$dataType}:{$windowWeeks}w",
                    'data_type' => $dataType,
                    'window_weeks' => $windowWeeks,
                    'raw_payload' => ['payload' => 'example'],
                ]);
            }
        }

        Http::fake(function (Request $request) {
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
                                'title' => 'Dematus 3.0',
                            ],
                        ],
                    ],
                ]);
            }

            if ($request->url() === 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id:batchUpdate') {
                return Http::response([
                    'replies' => [
                        [
                            'addSheet' => [
                                'properties' => [
                                    'title' => 'Dataset',
                                ],
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

            if (str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id/values:batchUpdate')) {
                return Http::response([
                    'totalUpdatedRows' => 2,
                ]);
            }

            return Http::response([], 500);
        });

        $this
            ->withSession([config('static-auth.session_key') => true])
            ->postJson(route('stratz.snapshots.export-dataset'))
            ->assertOk()
            ->assertJsonPath('type', 'snapshot_dataset_export')
            ->assertJsonPath('data.sheet_title', 'Dataset')
            ->assertJsonPath('data.exported_rows', 1)
            ->assertJsonPath("data.rows.{$snapshot->id}", 2);

        Http::assertSent(function (Request $request) use ($snapshot): bool {
            if (! str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id/values:batchUpdate')) {
                return false;
            }

            $data = (array) $request['data'];
            $rowUpdate = collect($data)->first(
                fn (array $range): bool => str_starts_with((string) data_get($range, 'range'), "'Dataset'!A2:"),
            );

            return data_get($rowUpdate, 'values.0.0') === (string) $snapshot->id
                && data_get($rowUpdate, 'values.0.3') === '8893106015'
                && data_get($rowUpdate, 'values.0.12') === 'yes'
                && data_get($rowUpdate, 'values.0.18') === '11.500'
                && count((array) data_get($rowUpdate, 'values.0')) > 23;
        });
    }

    public function test_it_preserves_manual_bookmaker_dataset_columns_on_export(): void
    {
        config()->set('services.google_sheets.spreadsheet_url', 'https://docs.google.com/spreadsheets/d/test-sheet-id/edit?gid=673477564');
        config()->set('services.google_sheets.service_account_credentials', $this->fakeGoogleCredentialsPath());
        config()->set('services.google_sheets.timeout', 20);

        $snapshot = DraftSnapshot::factory()->create([
            'id' => 77,
            'source' => 'match_id',
            'match_id' => 8893106015,
            'bookmaker_odds_radiant' => null,
            'bookmaker_odds_dire' => null,
        ]);

        Http::fake(function (Request $request) {
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
                                'title' => 'Dataset',
                            ],
                        ],
                    ],
                ]);
            }

            if (str_contains(rawurldecode($request->url()), "/values/'Dataset'!A:")) {
                $existingRow = array_fill(0, 60, '');
                $existingRow[0] = '77';
                $existingRow[20] = '2.150';
                $existingRow[21] = '1.740';

                return Http::response([
                    'range' => "'Dataset'!A:AZ",
                    'values' => [
                        ['snapshot_id'],
                        $existingRow,
                    ],
                ]);
            }

            if (str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id/values:batchUpdate')) {
                return Http::response([
                    'totalUpdatedRows' => 2,
                ]);
            }

            return Http::response([], 500);
        });

        $this
            ->withSession([config('static-auth.session_key') => true])
            ->postJson(route('stratz.snapshots.export-dataset'))
            ->assertOk()
            ->assertJsonPath('data.rows.77', 2);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/test-sheet-id/values:batchUpdate')) {
                return false;
            }

            $data = (array) $request['data'];
            $rowUpdate = collect($data)->first(
                fn (array $range): bool => str_starts_with((string) data_get($range, 'range'), "'Dataset'!A2:"),
            );

            return data_get($rowUpdate, 'values.0.20') === '2.150'
                && data_get($rowUpdate, 'values.0.21') === '1.740'
                && data_get($rowUpdate, 'values.0.22') === '';
        });
    }

    private function fakeGoogleCredentialsPath(): string
    {
        $directory = storage_path('framework/testing');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        preg_match(
            '/-----BEGIN PRIVATE KEY-----.*?-----END PRIVATE KEY-----/s',
            (string) file_get_contents(__DIR__.'/StratzRoshTest.php'),
            $matches,
        );

        $path = $directory.'/dataset-google-sheets-service-account.json';

        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'project_id' => 'test-project',
            'private_key_id' => 'test-private-key-id',
            'private_key' => $matches[0],
            'client_email' => 'test-service-account@example.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}
