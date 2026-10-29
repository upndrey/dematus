<?php

namespace Tests\Feature;

use App\Services\GoogleSheets\RoshSheetService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GoogleSheetsWriteLockTest extends TestCase
{
    public static function writers(): array
    {
        return [
            'web live' => ['appendLiveOdds', [[]]],
            'match import' => ['syncMatchOdds', [123, []]],
            'telegram' => ['syncBetBoomOdds', ['123', 1, []]],
            'dataset' => ['syncSnapshotDataset', [[]]],
            'first pick update' => ['syncSnapshotFirstPickSides', [[]]],
            'dataset repair' => ['repairSnapshotDatasetBookmakerColumnLayout', [[]]],
        ];
    }

    #[DataProvider('writers')]
    public function test_all_sheet_writers_use_the_same_lock_and_never_write_on_timeout(string $method, array $arguments): void
    {
        config(['services.google_sheets.spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/shared-sheet/edit#gid=0']);
        Http::preventStrayRequests();
        Http::fake();
        $lock = Mockery::mock();
        Cache::shouldReceive('lock')->once()->with('google-sheets:write:shared-sheet', 900)->andReturn($lock);
        $lock->shouldReceive('block')->once()->with(10, Mockery::type('callable'))->andThrow(new LockTimeoutException);
        try {
            app(RoshSheetService::class)->{$method}(...$arguments);
            $this->fail('A busy sheet must not be written without a lock.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Таблица занята', $error->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_lock_is_released_when_the_sheet_operation_fails(): void
    {
        config(['services.google_sheets.spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/shared-sheet/edit', 'services.google_sheets.service_account_credentials' => null]);
        Http::preventStrayRequests();
        $key = 'google-sheets:write:shared-sheet';
        try {
            app(RoshSheetService::class)->appendLiveOdds([]);
            $this->fail('Expected missing configuration.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('not configured', $error->getMessage());
        }
        $lock = Cache::lock($key, 900);
        $this->assertTrue($lock->get());
        $lock->release();
    }
}
