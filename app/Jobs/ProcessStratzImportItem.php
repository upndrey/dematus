<?php

namespace App\Jobs;

use App\Exceptions\ExternalHttpRequestException;
use App\Models\DraftSnapshot;
use App\Models\StratzImportBatch;
use App\Models\StratzImportItem;
use App\Services\DraftSnapshots\DraftSnapshotService;
use App\Services\GoogleSheets\RoshSheetService;
use App\Services\Stratz\StratzDataWindowCollector;
use App\Services\Stratz\StratzService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessStratzImportItem implements ShouldQueue
{
    use Queueable;

    private const STRATZ_COOLDOWN_CACHE_KEY = 'stratz-import-rate-limit-until';

    public int $tries = 12;

    public int $timeout = 180;

    /** @var list<int> */
    public array $backoff = [180, 300, 600];

    public function __construct(public int $itemId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('stratz-import'))
                ->releaseAfter(30)
                ->expireAfter(300),
        ];
    }

    public function handle(StratzService $stratzService, RoshSheetService $roshSheetService, DraftSnapshotService $draftSnapshotService, StratzDataWindowCollector $stratzDataWindowCollector): void
    {
        config()->set('services.stratz.request_interval_seconds', 3);
        $this->waitForStratzCooldown();
        $item = StratzImportItem::query()->findOrFail($this->itemId);

        if (in_array($item->status, ['succeeded', 'skipped', 'failed'], true)) {
            return;
        }

        $item->update(['status' => 'processing', 'attempts' => $item->attempts + 1, 'started_at' => now(), 'error_message' => null]);
        $batch = $item->batch;
        $batch->update(['status' => 'running']);
        $existingSnapshot = DraftSnapshot::query()->where('match_id', $item->match_id)->first();

        try {
            if ($existingSnapshot !== null) {
                $snapshot = $existingSnapshot;
            } else {
                $rosh = $stratzService->getRoshFromMatchId($item->match_id);

                if ($roshSheetService->isConfigured()) {
                    $rosh['google_sheets'] = $roshSheetService->syncMatchOdds($item->match_id, (array) data_get($rosh, 'formatted', []));
                }

                $snapshot = $draftSnapshotService->store('batch_import', ['match_id' => $item->match_id], $rosh, [
                    'match_id' => $item->match_id,
                    'tournament' => $batch->tournament,
                ]);
            }

            $stratzDataWindowCollector->collect($snapshot);
            $snapshot = $draftSnapshotService->refreshMlFeaturePayload($snapshot);

            if ($roshSheetService->isConfigured()) {
                $roshSheetService->syncSnapshotDataset([$snapshot->fresh('stratzDataBuckets')]);
            }

            $item->update(['status' => 'succeeded', 'draft_snapshot_id' => $snapshot->id, 'finished_at' => now()]);
            $this->finish($batch, 'succeeded_items');
        } catch (ExternalHttpRequestException $exception) {
            if ($exception->getCode() !== 429 && $this->isPermanentClientError($exception)) {
                $this->markAsFailed($item, $exception);

                if (in_array($exception->getCode(), [401, 403], true)) {
                    $this->markPendingBatchItemsAsFailed($batch, $exception);
                }

                return;
            }

            if ($exception->getCode() !== 429) {
                throw $exception;
            }

            $delay = $this->retryAfterSeconds($exception);
            Cache::put(self::STRATZ_COOLDOWN_CACHE_KEY, now()->addSeconds($delay)->timestamp, now()->addSeconds($delay));
            $item->update(['status' => 'queued', 'error_message' => null]);
            $this->release($delay);
        }
    }

    public function failed(Throwable $throwable): void
    {
        $item = StratzImportItem::query()->find($this->itemId);

        if ($item === null || in_array($item->status, ['succeeded', 'skipped', 'failed'], true)) {
            return;
        }

        $this->markAsFailed($item, $throwable);
    }

    private function finish(StratzImportBatch $batch, string $counter): void
    {
        $batch->increment('processed_items');
        $batch->increment($counter);
        $batch->refresh();

        if ($batch->processed_items >= $batch->total_items) {
            $batch->update(['status' => $batch->failed_items > 0 ? 'completed_with_errors' : 'completed']);
        }
    }

    private function isPermanentClientError(ExternalHttpRequestException $exception): bool
    {
        return $exception->getCode() >= 400 && $exception->getCode() < 500;
    }

    private function markAsFailed(StratzImportItem $item, Throwable $throwable): void
    {
        $item->update(['status' => 'failed', 'error_message' => $throwable->getMessage(), 'finished_at' => now()]);
        $this->finish($item->batch, 'failed_items');
    }

    private function markPendingBatchItemsAsFailed(StratzImportBatch $batch, Throwable $throwable): void
    {
        $pendingItems = $batch->items()
            ->whereIn('status', ['queued', 'processing'])
            ->get();

        if ($pendingItems->isEmpty()) {
            return;
        }

        $pendingItems->each(fn (StratzImportItem $item): int => $item->update([
            'status' => 'failed',
            'error_message' => $throwable->getMessage(),
            'finished_at' => now(),
        ]));

        $count = $pendingItems->count();
        $batch->increment('processed_items', $count);
        $batch->increment('failed_items', $count);
        $batch->refresh();

        if ($batch->processed_items >= $batch->total_items) {
            $batch->update(['status' => 'completed_with_errors']);
        }
    }

    private function waitForStratzCooldown(): void
    {
        while (true) {
            $cooldownUntil = Cache::get(self::STRATZ_COOLDOWN_CACHE_KEY);

            if (! is_numeric($cooldownUntil)) {
                return;
            }

            $secondsRemaining = (int) $cooldownUntil - now()->timestamp;

            if ($secondsRemaining <= 0) {
                Cache::forget(self::STRATZ_COOLDOWN_CACHE_KEY);

                return;
            }

            sleep(min($secondsRemaining, 120));
        }
    }

    private function retryAfterSeconds(ExternalHttpRequestException $exception): int
    {
        $retryAfter = data_get($exception->context(), 'headers.retry-after.0');

        return is_numeric($retryAfter) ? max(1, (int) $retryAfter) : 180;
    }
}
