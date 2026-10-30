<?php

namespace App\Services\Telegram;

use App\Models\TelegramDraft;
use App\Models\TelegramIgnoredMatch;
use App\Models\TelegramSubscriber;
use App\Models\TelegramWatch;
use App\Models\TelegramWatcherState;
use Illuminate\Support\Facades\Cache;

class WatcherControl
{
    public function state(): TelegramWatcherState
    {
        return TelegramWatcherState::query()->firstOrCreate(['id' => 1], ['running' => true, 'started_at' => now()]);
    }

    public function setRunning(bool $running): void
    {
        Cache::lock('telegram:watcher-control', 30)->block(5, function () use ($running): void {
            $state = $this->state();
            if ($state->running === $running) {
                return;
            }
            $state->update(['running' => $running, 'started_at' => $running ? now() : $state->started_at]);
        });
    }

    public function remind(): void
    {
        Cache::lock('telegram:watcher-control', 30)->block(5, function (): void {
            $state = $this->state();
            if (! $state->running || ! $state->started_at || $state->started_at->gt(now()->subHours(12))) {
                return;
            }
            foreach (TelegramSubscriber::query()->where('active', true)->get() as $subscriber) {
                if ($subscriber->watcher_reminded_at && $subscriber->watcher_reminded_at->gte($state->started_at)) {
                    continue;
                }
                try {
                    app(TelegramClient::class)->send($subscriber->chat_id, 'Анализатор работает уже 12 часов. /stop_watcher — остановить общий сбор матчей.');
                    $subscriber->update(['watcher_reminded_at' => now()]);
                } catch (\Throwable) {
                    continue;
                }
            }
        });
    }

    public function ignored(string $matchId): bool
    {
        return TelegramIgnoredMatch::query()->where('match_id', $matchId)->exists();
    }

    public function ignore(string $matchId, string $userId): string
    {
        return Cache::lock('telegram:match:'.$matchId, 90)->block(5, function () use ($matchId, $userId): string {
            if ($this->ignored($matchId)) {
                return 'Игра уже игнорируется.';
            }
            $drafts = TelegramDraft::query()->where('match_id', $matchId)->get();
            foreach ($drafts as $draft) {
                if (in_array($draft->status, ['calculating', 'done'], true) || $draft->calculation || $draft->draft_snapshot_id || $draft->sheet_result) {
                    return 'Расчёт уже начался или сохранён. Игнорирование не выполнено.';
                }
            }
            (new TelegramIgnoredMatch)->getConnection()->transaction(function () use ($matchId, $userId, $drafts): void {
                TelegramIgnoredMatch::query()->create(['match_id' => $matchId, 'ignored_by' => $userId]);
                TelegramWatch::query()->where('match_id', $matchId)->update(['active' => false]);
                foreach ($drafts as $draft) {
                    $draft->update(['status' => 'ignored', 'revision' => $draft->revision + 1, 'message_hash' => null]);
                }
            });

            return 'Игра и следующие карты исключены для всех. Сохранения не будет.';
        });
    }
}
