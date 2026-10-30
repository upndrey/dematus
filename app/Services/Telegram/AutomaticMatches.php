<?php

namespace App\Services\Telegram;

use App\Models\TelegramSubscriber;
use App\Models\TelegramWatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class AutomaticMatches
{
    public function receive(array $event): void
    {
        if (($event['event'] ?? '') === 'ended' && ctype_digit((string) ($event['matchId'] ?? ''))) {
            TelegramWatch::query()->where('match_id', $event['matchId'])->where('expires_at', '>', now()->addMinutes(2))->update(['expires_at' => now()->addMinutes(2)]);
        }
        if (($event['event'] ?? '') === 'schedule' && is_array($event['matches'] ?? null)) {
            Cache::put('telegram:schedule', $event['matches'], 180);
        }
    }

    public function tick(): void
    {
        if (! app(WatcherControl::class)->state()->running) {
            return;
        }
        $matches = Cache::get('telegram:schedule', []);
        foreach ($matches as $match) {
            if (! ctype_digit((string) ($match['id'] ?? '')) || empty($match['start'])) {
                continue;
            }
            if (app(WatcherControl::class)->ignored((string) $match['id'])) {
                continue;
            }
            $start = Carbon::parse($match['start']);
            if ($start->gt(now()->addMinutes(10)) || $start->lt(now()->subHours(12))) {
                continue;
            }
            Cache::put('telegram:match-start:'.$match['id'], $start->toIso8601String(), 86400);
            foreach (TelegramSubscriber::query()->where('active', true)->get() as $subscriber) {
                $watch = TelegramWatch::query()->firstOrCreate(['chat_id' => $subscriber->chat_id, 'match_id' => (string) $match['id']], ['active' => true, 'expires_at' => $start->copy()->addHours(12)]);
                if (! $watch->active) {
                    continue;
                }
                $key = 'telegram:announced:'.$subscriber->chat_id.':'.$match['id'];
                if (! Cache::has($key)) {
                    try {
                        app(TelegramClient::class)->send($subscriber->chat_id, ($start->isFuture() ? 'Скоро начнётся матч: ' : 'Наблюдаем за матчем: ').$match['home'].' — '.$match['away']."\n".$match['tournament']."\nНачало по расписанию: ".$start->copy()->timezone('Asia/Novosibirsk')->format('d.m H:i').' (Новосибирск). Дождусь выбора героев и пришлю составы для проверки.', [[['text' => 'Игнорировать игру', 'callback_data' => 'ignore:'.$match['id']]]]);
                        Cache::put($key, true, now()->addDays(14));
                    } catch (\Throwable) {
                        continue;
                    }
                }
            }
        }
    }
}
