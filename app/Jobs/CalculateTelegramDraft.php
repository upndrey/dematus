<?php

namespace App\Jobs;

use App\Models\TelegramDraft;
use App\Services\Telegram\DraftBot;
use App\Services\Telegram\DraftCalculation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CalculateTelegramDraft implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $draftId, public int $revision) {}

    public function handle(DraftCalculation $calculation, DraftBot $bot): void
    {
        $lock = Cache::lock('telegram:calculate:'.$this->draftId, 1000);
        if (! $lock->get()) {
            return;
        }
        try {
            $draft = TelegramDraft::query()->findOrFail($this->draftId);
            $claimed = Cache::lock('telegram:draft:'.$draft->match_id.':'.$draft->map_number, 90)->block(10, function () use ($draft): bool {
                $draft->refresh();
                if ($draft->revision !== $this->revision || ! in_array($draft->status, ['queued', 'calculating'], true)) {
                    return false;
                }
                $draft->update(['status' => 'calculating', 'message_hash' => null]);

                return true;
            });
            if (! $claimed) {
                return;
            }
            try {
                $calculation->run($draft);
            } catch (Throwable $error) {
                $draft->update(['status' => 'failed', 'error' => 'Calculation or sheet synchronization failed.', 'message_hash' => null]);
                Log::warning('Telegram draft calculation failed', ['draft_id' => $draft->id, 'exception_class' => $error::class]);
            }
            try {
                $bot->deliver($draft->fresh());
            } catch (Throwable) {
                Log::warning('Telegram result notification pending', ['draft_id' => $draft->id]);
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        TelegramDraft::query()->whereKey($this->draftId)->where('revision', $this->revision)->whereIn('status', ['queued', 'calculating'])->update(['status' => 'failed', 'error' => 'Calculation worker stopped.', 'message_hash' => null]);
    }
}
