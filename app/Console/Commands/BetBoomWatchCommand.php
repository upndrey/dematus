<?php

namespace App\Console\Commands;

use App\Models\TelegramIgnoredMatch;
use App\Models\TelegramSubscriber;
use App\Models\TelegramWatch;
use App\Services\Telegram\AutomaticMatches;
use App\Services\Telegram\DraftBot;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\WatcherControl;
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
        $discovery = null;
        $discoveryBuffer = '';
        $nextTick = 0;
        $nextDiscovery = 0;
        $discoveryIgnores = null;
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
                $running = app(WatcherControl::class)->state()->running;
                if ($running && ! $this->option('once') && TelegramSubscriber::query()->where('active', true)->exists()) {
                    $ignored = TelegramIgnoredMatch::query()->orderBy('match_id')->pluck('match_id')->all();
                    if ($discovery && $discoveryIgnores !== $ignored) {
                        $discovery->stop();
                        $discovery = null;
                        $nextDiscovery = 0;
                    }
                    if ((! $discovery || ! $discovery->running()) && time() >= $nextDiscovery) {
                        $discovery = Process::forever()->start([config('telegram.node_binary'), resource_path('js/betboom/discover.mjs'), json_encode($ignored)]);
                        $discoveryIgnores = $ignored;
                        $nextDiscovery = time() + 300;
                        $discoveryBuffer = '';
                    }
                    $discoveryBuffer .= ($discovery?->latestOutput() ?? '');
                    $discovery?->latestErrorOutput();
                    while (($end = strpos($discoveryBuffer, "\n")) !== false) {
                        $event = json_decode(substr($discoveryBuffer, 0, $end), true);
                        $discoveryBuffer = substr($discoveryBuffer, $end + 1);
                        if (is_array($event)) {
                            if (($event['event'] ?? '') === 'idle') {
                                $nextDiscovery = time() + 300;
                            }
                            app(AutomaticMatches::class)->receive($event);
                        }
                    }
                    if (time() >= $nextTick) {
                        app(WatcherControl::class)->remind();
                        app(AutomaticMatches::class)->tick();
                        $nextTick = time() + 10;
                    }
                } else {
                    $discovery?->stop();
                    $discovery = null;
                    $nextDiscovery = 0;
                    $nextTick = 0;
                }
                $watches = TelegramWatch::query()->where('active', true)->whereNotIn('match_id', TelegramIgnoredMatch::query()->select('match_id'))->when(! $running, fn ($query) => $query->whereRaw('1 = 0'))->where('expires_at', '>', now())->get();
                foreach ($processes as $matchId => $process) {
                    if (! $watches->contains('match_id', (string) $matchId)) {
                        $process->stop();
                        unset($processes[$matchId], $buffers[$matchId]);
                    }
                }
                foreach ($watches->groupBy('match_id') as $matchId => $subscribers) {
                    if (! isset($processes[$matchId]) || ! $processes[$matchId]->running()) {
                        $arguments = [config('telegram.node_binary'), resource_path('js/betboom/watch.mjs'), (string) $matchId];
                        if ($start = Cache::get('telegram:match-start:'.$matchId)) {
                            $arguments[] = $start;
                        }
                        $processes[$matchId] = Process::forever()->start($arguments);
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
            $discovery?->stop();
            foreach ($processes as $process) {
                $process->stop();
            }
            $lock->release();
        }

        return self::SUCCESS;
    }
}
