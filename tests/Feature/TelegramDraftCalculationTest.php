<?php

namespace Tests\Feature;

use App\Jobs\CalculateTelegramDraft;
use App\Models\DraftSnapshot;
use App\Models\TelegramDraft;
use App\Services\DraftSnapshots\DraftSnapshotService;
use App\Services\GoogleSheets\RoshSheetService;
use App\Services\Stratz\StratzDataWindowCollector;
use App\Services\Stratz\StratzService;
use App\Services\Telegram\BetBoomDraftSource;
use App\Services\Telegram\DraftBot;
use App\Services\Telegram\DraftCalculation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TelegramDraftCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function draft(): TelegramDraft
    {
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/betboom/map2.json')), true);

        return TelegramDraft::factory()->create(['players' => app(BetBoomDraftSource::class)->players($payload), 'match_id' => '6344550', 'map_number' => 2, 'confirmed_at' => now(), 'confirmed_by' => '42', 'status' => 'queued']);
    }

    public function test_uses_standard_calculation_and_retries_dataset_without_recalculating_or_appending_twice(): void
    {
        $draft = $this->draft();
        $payload = app(BetBoomDraftSource::class)->calculationPayload($draft->players);
        $rosh = ['formatted' => ['radiant_team' => 'LEGION', 'dire_team' => 'Team Spirit', 'radiant_odds_1' => 0.4, 'dire_odds_1' => 0.6]];
        $snapshot = DraftSnapshot::factory()->create(['match_id' => null]);
        $stratz = $this->mock(StratzService::class);
        $stratz->shouldReceive('getRoshFromHeroes')->once()->with($payload)->andReturn($rosh);
        $snapshots = $this->mock(DraftSnapshotService::class);
        $snapshots->shouldReceive('store')->once()->with('betboom', Mockery::on(fn (array $p): bool => $p['radiant_heroes'] === [21, 106, 155, 119, 50] && $p['dire_heroes'] === [145, 16, 29, 30, 123] && $p['confirmed_by'] === '42'), $rosh, Mockery::type('array'))->andReturn($snapshot);
        $snapshots->shouldReceive('refreshMlFeaturePayload')->twice()->andReturn($snapshot);
        $this->mock(StratzDataWindowCollector::class)->shouldReceive('collect')->twice();
        $sheets = $this->mock(RoshSheetService::class);
        $sheets->shouldReceive('isConfigured')->twice()->andReturn(true);
        $sheets->shouldReceive('syncBetBoomOdds')->once()->with('6344550', 2, $rosh['formatted'])->andReturn(['row' => 17, 'sheet_title' => 'Tournament']);
        $attempt = 0;
        $sheets->shouldReceive('syncSnapshotDataset')->twice()->andReturnUsing(function () use (&$attempt): array {
            if (++$attempt === 1) {
                throw new RuntimeException('Dataset unavailable');
            }

            return ['rows' => [17]];
        });
        $service = app(DraftCalculation::class);
        try {
            $service->run($draft);
            $this->fail('Expected a dataset failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Dataset unavailable', $error->getMessage());
        }
        $this->assertSame(17, $draft->fresh()->sheet_result['row']);
        $service->run($draft->fresh());
        $this->assertSame('done', $draft->fresh()->status);
        $this->assertSame($snapshot->id, $draft->fresh()->draft_snapshot_id);
        $this->assertSame(17, $snapshot->fresh()->sheet_payload['row']);
        Http::assertNothingSent();
    }

    public function test_calculation_failure_keeps_retry_state_and_a_completed_job_cannot_repeat(): void
    {
        $draft = $this->draft();
        $calculation = $this->mock(DraftCalculation::class);
        $calculation->shouldReceive('run')->once()->andThrow(new RuntimeException('Unavailable'));
        $bot = $this->mock(DraftBot::class);
        $bot->shouldReceive('deliver')->once();
        (new CalculateTelegramDraft($draft->id, $draft->revision))->handle($calculation, $bot);
        $this->assertSame('failed', $draft->fresh()->status);
        $draft->update(['status' => 'done']);
        (new CalculateTelegramDraft($draft->id, $draft->revision))->handle($calculation, $bot);
        Http::assertNothingSent();
    }

    public function test_stale_queued_revision_is_never_calculated(): void
    {
        $draft = $this->draft();
        $calculation = $this->mock(DraftCalculation::class);
        $calculation->shouldNotReceive('run');
        $bot = $this->mock(DraftBot::class);
        $bot->shouldNotReceive('deliver');
        (new CalculateTelegramDraft($draft->id, $draft->revision - 1))->handle($calculation, $bot);
        $this->assertSame('queued', $draft->fresh()->status);
    }

    public function test_worker_termination_exposes_retry_without_losing_calculation(): void
    {
        $draft = $this->draft();
        $draft->update(['status' => 'calculating', 'calculation' => ['formatted' => []]]);
        (new CalculateTelegramDraft($draft->id, $draft->revision))->failed(new RuntimeException('Worker timeout'));
        $this->assertSame('failed', $draft->fresh()->status);
        $this->assertSame(['formatted' => []], $draft->fresh()->calculation);
    }

    public function test_sheet_retry_reuses_the_same_betboom_map_row(): void
    {
        $opensslConfig = tempnam(sys_get_temp_dir(), 'dematus-openssl-');
        file_put_contents($opensslConfig, "[req]\ndistinguished_name=req_distinguished_name\n[req_distinguished_name]\n");
        try {
            $key = openssl_pkey_new(['config' => $opensslConfig, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $privateKey, null, ['config' => $opensslConfig]);
        } finally {
            unlink($opensslConfig);
        }
        $path = tempnam(sys_get_temp_dir(), 'dematus-test-');
        file_put_contents($path, json_encode(['type' => 'service_account', 'private_key_id' => 'test', 'private_key' => $privateKey, 'client_email' => 'test@example.com', 'token_uri' => 'https://oauth2.googleapis.com/token']));
        config(['services.google_sheets.spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/test-sheet/edit#gid=0', 'services.google_sheets.service_account_credentials' => $path]);
        $rows = [['Match']];
        $writes = [];
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$rows, &$writes) {
            $url = rawurldecode($request->url());
            if ($url === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'fake']);
            }
            if (str_contains($url, 'fields=')) {
                return Http::response(['sheets' => [['properties' => ['sheetId' => 0, 'title' => 'Test', 'index' => 0, 'gridProperties' => ['rowCount' => 100]]]]]);
            }
            if (str_contains($url, "/values/'Test'!A:A")) {
                return Http::response(['values' => $rows]);
            }
            if (str_contains($url, '/values:batchUpdate')) {
                $writes[] = $request['data'][0];
                $rows[1] = $request['data'][0]['values'][0];

                return Http::response([]);
            }
            if (str_contains($url, '/values:batchGet')) {
                return Http::response(['valueRanges' => []]);
            }
            throw new RuntimeException('Unexpected mocked HTTP request');
        });
        try {
            $service = app(RoshSheetService::class);
            $first = $service->syncBetBoomOdds('6344550', 2, []);
            $second = $service->syncBetBoomOdds('6344550', 2, []);
            $this->assertSame(2, $first['row']);
            $this->assertSame(2, $second['row']);
            $this->assertSame($writes[0], $writes[1]);
            $this->assertSame(['BETBOOM:6344550:2'], $rows[1]);
        } finally {
            unlink($path);
        }
    }
}
