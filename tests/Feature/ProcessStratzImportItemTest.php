<?php

namespace Tests\Feature;

use App\Exceptions\ExternalHttpRequestException;
use App\Jobs\ProcessStratzImportItem;
use App\Models\DraftSnapshot;
use App\Models\StratzImportBatch;
use App\Services\DraftSnapshots\DraftSnapshotService;
use App\Services\GoogleSheets\RoshSheetService;
use App\Services\Stratz\StratzDataWindowCollector;
use App\Services\Stratz\StratzService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProcessStratzImportItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_an_item_as_failed_without_retrying_a_permanent_stratz_error(): void
    {
        $batch = StratzImportBatch::query()->create([
            'status' => 'queued',
            'total_items' => 2,
        ]);
        $item = $batch->items()->create(['match_id' => 8960577698]);
        $pendingItem = $batch->items()->create(['match_id' => 8960577699]);
        $stratzService = Mockery::mock(StratzService::class);
        $stratzService
            ->shouldReceive('getRoshFromMatchId')
            ->once()
            ->andThrow(new ExternalHttpRequestException('STRATZ token is tied to another public IP address.', [], 403));

        (new ProcessStratzImportItem($item->id))->handle(
            $stratzService,
            Mockery::mock(RoshSheetService::class),
            Mockery::mock(DraftSnapshotService::class),
            Mockery::mock(StratzDataWindowCollector::class),
        );

        $this->assertDatabaseHas('stratz_import_items', [
            'id' => $item->id,
            'status' => 'failed',
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('stratz_import_items', [
            'id' => $pendingItem->id,
            'status' => 'failed',
            'attempts' => 0,
        ]);
        $this->assertDatabaseHas('stratz_import_batches', [
            'id' => $batch->id,
            'status' => 'completed_with_errors',
            'processed_items' => 2,
            'failed_items' => 2,
        ]);
    }

    public function test_it_does_not_sync_an_incomplete_snapshot_dataset(): void
    {
        $batch = StratzImportBatch::query()->create([
            'status' => 'queued',
            'total_items' => 1,
        ]);
        $item = $batch->items()->create(['match_id' => 8960577698]);
        $snapshot = DraftSnapshot::factory()->create(['match_id' => 8960577697]);

        $stratzService = Mockery::mock(StratzService::class);
        $stratzService->shouldReceive('getRoshFromMatchId')->once()->andReturn(['formatted' => []]);

        $roshSheetService = Mockery::mock(RoshSheetService::class);
        $roshSheetService->shouldReceive('isConfigured')->once()->andReturnTrue();
        $roshSheetService->shouldReceive('syncMatchOdds')->once()->andReturn([]);
        $roshSheetService->shouldNotReceive('syncSnapshotDataset');

        $draftSnapshotService = Mockery::mock(DraftSnapshotService::class);
        $draftSnapshotService->shouldReceive('store')->once()->andReturn($snapshot);
        $draftSnapshotService->shouldNotReceive('refreshMlFeaturePayload');

        $stratzDataWindowCollector = Mockery::mock(StratzDataWindowCollector::class);
        $stratzDataWindowCollector
            ->shouldReceive('collect')
            ->once()
            ->andThrow(new ExternalHttpRequestException('STRATZ is temporarily unavailable.', [], 500));

        $this->expectException(ExternalHttpRequestException::class);

        (new ProcessStratzImportItem($item->id))->handle(
            $stratzService,
            $roshSheetService,
            $draftSnapshotService,
            $stratzDataWindowCollector,
        );
    }

    public function test_it_completes_and_syncs_an_existing_snapshot_on_retry(): void
    {
        $batch = StratzImportBatch::query()->create([
            'status' => 'queued',
            'total_items' => 1,
        ]);
        $item = $batch->items()->create(['match_id' => 8960577698]);
        $snapshot = DraftSnapshot::factory()->create(['match_id' => $item->match_id]);

        $stratzService = Mockery::mock(StratzService::class);
        $stratzService->shouldNotReceive('getRoshFromMatchId');

        $roshSheetService = Mockery::mock(RoshSheetService::class);
        $roshSheetService->shouldReceive('isConfigured')->once()->andReturnTrue();
        $roshSheetService->shouldReceive('syncSnapshotDataset')->once()->andReturn(['rows' => [$snapshot->id]]);

        $draftSnapshotService = Mockery::mock(DraftSnapshotService::class);
        $draftSnapshotService->shouldNotReceive('store');
        $draftSnapshotService->shouldReceive('refreshMlFeaturePayload')->once()->andReturn($snapshot);

        $stratzDataWindowCollector = Mockery::mock(StratzDataWindowCollector::class);
        $stratzDataWindowCollector->shouldReceive('collect')->once()->with(Mockery::on(
            fn (DraftSnapshot $collectedSnapshot): bool => $collectedSnapshot->is($snapshot),
        ));

        (new ProcessStratzImportItem($item->id))->handle(
            $stratzService,
            $roshSheetService,
            $draftSnapshotService,
            $stratzDataWindowCollector,
        );

        $this->assertDatabaseHas('stratz_import_items', [
            'id' => $item->id,
            'status' => 'succeeded',
            'draft_snapshot_id' => $snapshot->id,
        ]);
    }

    public function test_it_does_not_reprocess_a_previously_failed_item(): void
    {
        $batch = StratzImportBatch::query()->create([
            'status' => 'completed_with_errors',
            'total_items' => 1,
            'processed_items' => 1,
            'failed_items' => 1,
        ]);
        $item = $batch->items()->create([
            'match_id' => 8960577698,
            'status' => 'failed',
        ]);

        (new ProcessStratzImportItem($item->id))->handle(
            Mockery::mock(StratzService::class),
            Mockery::mock(RoshSheetService::class),
            Mockery::mock(DraftSnapshotService::class),
            Mockery::mock(StratzDataWindowCollector::class),
        );

        $this->assertDatabaseHas('stratz_import_items', [
            'id' => $item->id,
            'status' => 'failed',
            'attempts' => 0,
        ]);
    }
}
