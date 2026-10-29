<?php

namespace App\Console\Commands;

use App\Models\TelegramWatch;
use App\Services\Telegram\DraftBot;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

class BetBoomWatchCommand extends Command
{
    protected $signature = 'telegram:watch {--once : Process one collector iteration}';

    protected $description = 'Collect BetBoom drafts for active Telegram subscriptions';

    public function handle(DraftBot $bot, TelegramClient $telegram): int
    {
        $processes = [];
        $buffers = [];
        $warnings = [];
        $connected = [];
        $lock = Cache::lock('telegram:watcher', 120);
        if (! $lock->get()) {
            $this->error('A watcher is already running.');

            return self::FAILURE;
        }
        try {
            do {
                $watches = TelegramWatch::query()->where('active', true)->where('expires_at', '>', now())->get();
                foreach ($processes as $matchId => $process) {
                    if (! $watches->contains('match_id', (string) $matchId)) {
                        $process->stop();
                        unset($processes[$matchId], $buffers[$matchId]);
                    }
                }
                foreach ($watches->groupBy('match_id') as $matchId => $subscribers) {
                    if (! isset($processes[$matchId]) || ! $processes[$matchId]->running()) {
                        $processes[$matchId] = Process::forever()->start([config('telegram.node_binary'), resource_path('js/betboom/watch.mjs'), (string) $matchId]);
                        $buffers[$matchId] = '';
                    }
                    $buffers[$matchId] .= $processes[$matchId]->latestOutput();
                    $processes[$matchId]->latestErrorOutput();
                    while (($end = strpos($buffers[$matchId], "\n")) !== false) {
                        $line = substr($buffers[$matchId], 0, $end);
                        $buffers[$matchId] = substr($buffers[$matchId], $end + 1);
                        $payload = json_decode($line, true);
                        if (! is_array($payload)) {
                            continue;
                        }
                        foreach ($subscribers as $watch) {
                            try {
                                $subscription = $watch->id.':'.$watch->expires_at->getTimestamp();
                                if (($payload['event'] ?? '') === 'connected' && (string) ($payload['matchId'] ?? '') === (string) $matchId && ! isset($connected[$subscription])) {
                                    $telegram->send($watch->chat_id, 'Подключились к матчу '.$matchId.'. Получаем данные. Когда все 10 героев будут привязаны к игрокам, пришлю составы для проверки.');
                                    $connected[$subscription] = true;
                                }
                                if (($payload['event'] ?? '') === 'draft') {
                                    $bot->ingest($watch->chat_id, $payload);
                                } elseif (($payload['code'] ?? '') === 'source_unavailable' && ! isset($warnings[$watch->id])) {
                                    $telegram->send($watch->chat_id, 'Драфт матча '.$matchId.' пока недоступен. Продолжаю попытки подключения. /stop — остановить.');
                                    $warnings[$watch->id] = true;
                                }
                            } catch (Throwable $error) {
                                $this->warn('Draft update failed: '.$error::class);
                            }
                        }
                    }
                }
                $lock->release();
                if (! $lock->get()) {
                    break;
                }
                if (! $this->option('once')) {
                    usleep(500000);
                }
            } while (! $this->option('once'));
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            $lock->release();
        }

        return self::SUCCESS;
    }
}
