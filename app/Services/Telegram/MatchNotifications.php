<?php

namespace App\Services\Telegram;

use App\Models\TelegramWatch;
use Illuminate\Support\Facades\Cache;

class MatchNotifications
{
    public function send(string $chatId, string $matchId, string $text, array $keyboard = []): void
    {
        Cache::lock('telegram:match:'.$matchId, 90)->block(5, function () use ($chatId, $matchId, $text, $keyboard): void {
            if (app(WatcherControl::class)->ignored($matchId)) {
                return;
            }
            $watch = TelegramWatch::query()->where('chat_id', $chatId)->where('match_id', $matchId)->firstOrFail();
            $message = app(TelegramClient::class)->send($chatId, $text, $keyboard);
            $messages = $watch->notification_messages ?? [];
            $messages[(string) $message['message_id']] = false;
            $watch->update(['notification_messages' => $messages]);
        });
    }

    public function rememberClicked(string $chatId, string $matchId, string $messageId): void
    {
        if (! ctype_digit($messageId)) {
            return;
        }
        Cache::lock('telegram:match:'.$matchId, 90)->block(5, function () use ($chatId, $matchId, $messageId): void {
            $watch = TelegramWatch::query()->where('chat_id', $chatId)->where('match_id', $matchId)->firstOrFail();
            $messages = $watch->notification_messages ?? [];
            $messages[$messageId] = false;
            $watch->update(['notification_messages' => $messages, 'ignore_update_pending' => app(WatcherControl::class)->ignored($matchId)]);
        });
    }

    public function flush(?string $matchId = null): void
    {
        $query = TelegramWatch::query()->where('ignore_update_pending', true)->orderBy('updated_at');
        if ($matchId !== null) {
            $query->where('match_id', $matchId);
        }
        foreach ($query->limit(20)->get() as $watch) {
            if ($matchId === null && Cache::has('telegram:ignore-retry:'.$watch->id)) {
                continue;
            }
            Cache::lock('telegram:match:'.$watch->match_id, 90)->block(5, function () use ($watch): void {
                $watch->refresh();
                if (! $watch->ignore_update_pending || ! app(WatcherControl::class)->ignored($watch->match_id)) {
                    return;
                }
                $text = 'Игра BetBoom '.$watch->match_id.' проигнорирована для всех. Наблюдение за этой серией остановлено, следующие карты исключены. Расчёта и сохранения в таблицу не будет.';
                $failed = false;
                try {
                    if (! $watch->ignored_message_id) {
                        $message = app(TelegramClient::class)->send($watch->chat_id, $text);
                        $watch->update(['ignored_message_id' => (string) $message['message_id']]);
                    }
                } catch (\Throwable) {
                    $failed = true;
                }
                $messages = $watch->notification_messages ?? [];
                foreach ($messages as $id => $done) {
                    if ($done) {
                        continue;
                    }
                    try {
                        app(TelegramClient::class)->call('editMessageText', ['chat_id' => $watch->chat_id, 'message_id' => (string) $id, 'text' => $text, 'reply_markup' => ['inline_keyboard' => []]]);
                        $messages[$id] = true;
                        $watch->update(['notification_messages' => $messages]);
                    } catch (\Throwable) {
                        $failed = true;
                    }
                }
                $watch->update(['ignore_update_pending' => $failed]);
                if ($failed) {
                    Cache::put('telegram:ignore-retry:'.$watch->id,true,60);
                }
            });
        }
    }
}
