<?php

namespace App\Console\Commands;

use App\Models\TelegramDraft;
use App\Services\Telegram\DraftBot;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TelegramBotCommand extends Command
{
    protected $signature = 'telegram:bot {--once : Process one batch of updates}';

    protected $description = 'Receive Telegram draft confirmations using long polling';

    public function handle(TelegramClient $telegram, DraftBot $bot): int
    {
        if (! config('telegram.token') || ! config('telegram.owner_id')) {
            $this->error('Configure TELEGRAM_BOT_TOKEN and TELEGRAM_OWNER_ID first.');

            return self::FAILURE;
        }
        $lock = Cache::lock('telegram:poller', 600);
        if (! $lock->get()) {
            $this->error('Telegram polling is already running.');

            return self::FAILURE;
        }
        try {
            do {
                try {
                    foreach (TelegramDraft::query()->whereNull('message_hash')->limit(5)->get() as $draft) {
                        try {
                            $bot->deliver($draft);
                        } catch (Throwable) {
                            $this->warn('Draft notification will be retried.');
                        }
                    }
                    $updates = $telegram->call('getUpdates', ['offset' => (int) Cache::get('telegram:offset', 0), 'limit' => 20, 'timeout' => $this->option('once') ? 0 : 20, 'allowed_updates' => ['message', 'callback_query']]);
                    foreach ($updates as $update) {
                        $bot->update($update);
                        Cache::forever('telegram:offset', $update['update_id'] + 1);
                    }
                } catch (Throwable $error) {
                    $this->warn('Telegram batch failed: '.$error::class);
                    if (! $this->option('once')) {
                        sleep(3);
                    }
                }
                $lock->release();
                if (! $lock->get()) {
                    break;
                }
            } while (! $this->option('once'));
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
