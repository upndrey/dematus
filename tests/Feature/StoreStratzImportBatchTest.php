<?php

namespace Tests\Feature;

use App\Jobs\ProcessStratzImportItem;
use App\Models\StratzImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StoreStratzImportBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_import_items_on_the_database_connection(): void
    {
        Queue::fake();

        $response = $this->authenticateStatically()->postJson(route('stratz.import-batches.store'), [
            'match_ids' => [8902338515, 8902431810],
            'tournament' => 'Esports World Cup 2026',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.total_items', 2);
        $this->assertDatabaseHas('stratz_import_batches', ['tournament' => 'Esports World Cup 2026']);

        Queue::assertPushed(ProcessStratzImportItem::class, 2);
        Queue::assertPushed(ProcessStratzImportItem::class, function (ProcessStratzImportItem $job): bool {
            return $job->connection === 'database' && $job->queue === 'stratz-import';
        });
    }

    public function test_import_jobs_keep_a_finite_global_lock_and_wait_before_retrying(): void
    {
        $middleware = (new ProcessStratzImportItem(1))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame(30, $middleware[0]->releaseAfter);
        $this->assertSame(300, $middleware[0]->expiresAfter);
    }

    public function test_it_reuses_an_active_batch_with_the_same_match_ids(): void
    {
        Queue::fake();

        $batch = StratzImportBatch::query()->create([
            'status' => 'queued',
            'total_items' => 2,
        ]);
        $batch->items()->create(['match_id' => 8902338515]);
        $batch->items()->create(['match_id' => 8902431810]);

        $response = $this->authenticateStatically()->postJson(route('stratz.import-batches.store'), [
            'match_ids' => [8902431810, 8902338515],
            'tournament' => 'Esports World Cup 2026',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.id', $batch->id);
        $this->assertDatabaseCount('stratz_import_batches', 1);
        Queue::assertNothingPushed();
    }
}
