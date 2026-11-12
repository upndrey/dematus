<?php

namespace Tests\Feature;

use App\Jobs\CalculateTelegramDraft;
use App\Models\TelegramDraft;
use App\Models\TelegramIgnoredMatch;
use App\Models\TelegramSubscriber;
use App\Models\TelegramWatch;
use App\Services\Telegram\AutomaticMatches;
use App\Services\Telegram\DraftBot;
use App\Services\Telegram\DraftCalculation;
use App\Services\Telegram\WatcherControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramWatcherControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.token' => 'test', 'telegram.owner_id' => '42', 'telegram.allowed_chats' => ['43']]);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
        Queue::fake();
    }

    private function command(string $text, string $user = '42'): void
    {
        app(DraftBot::class)->update(['message' => ['chat' => ['id' => $user], 'from' => ['id' => $user], 'text' => $text]]);
    }

    private function draft(): TelegramDraft
    {
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/betboom/map2.json')), true);
        $payload['receivedAt'] = now()->getTimestampMs();
        foreach (['42', '43'] as $chat) {
            TelegramWatch::query()->create(['chat_id' => $chat, 'match_id' => '6344550', 'active' => true, 'expires_at' => now()->addHours(12)]);
        }
        $bot = app(DraftBot::class);
        $draft = $bot->ingest('42', $payload);
        $bot->ingest('43', $payload);

        return $draft;
    }

    public function test_commands_are_global_accept_aliases_and_keep_start_time_on_repeat(): void
    {
        $control = app(WatcherControl::class);
        $this->command('/stop-watcher');
        $this->assertFalse($control->state()->running);
        $this->command('/start_watcher');
        $start = $control->state()->started_at;
        $this->travel(1)->hours();
        $this->command('/start-watcher');
        $this->assertEquals($start, $control->state()->started_at);
        $this->assertTrue(TelegramSubscriber::query()->sole()->active);
        $this->command('/stop_watcher', '99');
        $this->assertTrue($control->state()->running);
        $this->command('/stop_watcher', '43');
        $this->assertFalse($control->state()->running);
    }

    public function test_reminder_is_once_per_chat_per_run_and_resets_only_after_restart(): void
    {
        TelegramSubscriber::query()->create(['chat_id' => '42']);
        TelegramSubscriber::query()->create(['chat_id' => '43']);
        $control = app(WatcherControl::class);
        $control->state();
        $this->travel(719)->minutes();
        $control->remind();
        Http::assertNothingSent();
        $this->travel(1)->minutes();
        $control->remind();
        $control->remind();
        Http::assertSentCount(2);
        $control->setRunning(false);
        $this->travel(13)->hours();
        $control->remind();
        Http::assertSentCount(2);
        $control->setRunning(true);
        $control->remind();
        Http::assertSentCount(2);
        $this->travel(12)->hours();
        $control->remind();
        Http::assertSentCount(4);
    }

    public function test_paused_watcher_starts_no_process_and_auto_tick_creates_no_watch(): void
    {
        TelegramSubscriber::query()->create(['chat_id' => '42']);
        TelegramWatch::query()->create(['chat_id' => '42', 'match_id' => '123', 'active' => true, 'expires_at' => now()->addHour()]);
        app(WatcherControl::class)->setRunning(false);
        Process::shouldReceive('forever')->never();
        $this->artisan('telegram:watch --once')->assertSuccessful();
        Http::assertNothingSent();
        app(AutomaticMatches::class)->receive(['event' => 'schedule', 'matches' => [['id' => '456', 'start' => now()->toIso8601String(), 'home' => 'A', 'away' => 'B', 'tournament' => 'Cup']]]);
        app(AutomaticMatches::class)->tick();
        $this->assertSame(1, TelegramWatch::query()->count());
    }

    public function test_ignore_cancels_queued_job_and_prevents_all_future_maps_and_manual_watch(): void
    {
        $draft = $this->draft();
        $revision = $draft->revision;
        $draft->update(['status' => 'queued']);
        app(DraftBot::class)->callback(['id' => 'ignore', 'from' => ['id' => '43'], 'message' => ['chat' => ['id' => '43'], 'message_id' => 77], 'data' => 'ignore:6344550']);
        $this->assertTrue(app(WatcherControl::class)->ignored('6344550'));
        $this->assertSame('ignored', $draft->fresh()->status);
        $this->assertSame(0, TelegramWatch::query()->where('active', true)->count());
        $calculation = $this->mock(DraftCalculation::class);
        $calculation->shouldNotReceive('run');
        (new CalculateTelegramDraft($draft->id, $revision))->handle($calculation, app(DraftBot::class));
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/betboom/map2.json')), true);
        $payload['map'] = 3;
        $payload['receivedAt'] = now()->getTimestampMs();
        $this->assertNull(app(DraftBot::class)->ingest('42', $payload));
        $this->command('/watch https://betboom.ru/esport/dota-2/39442/6344550');
        $this->assertSame(0, TelegramWatch::query()->where('active', true)->count());
        $this->assertSame(1, TelegramDraft::query()->count());
        $this->assertNull($draft->fresh()->sheet_result);
        Queue::assertNothingPushed();
    }

    public function test_ignore_is_refused_after_calculation_started_and_unauthorized_callbacks_do_nothing(): void
    {
        $draft = $this->draft();
        $draft->update(['status' => 'calculating']);
        app(WatcherControl::class)->ignore($draft->match_id, '42');
        $this->assertSame(0, TelegramIgnoredMatch::query()->count());
        $draft->update(['status' => 'draft']);
        app(DraftBot::class)->callback(['id' => 'bad', 'from' => ['id' => '99'], 'message' => ['chat' => ['id' => '99']], 'data' => 'ignore:6344550']);
        $this->assertSame(0, TelegramIgnoredMatch::query()->count());
    }

    public function test_ignored_match_is_not_rediscovered_and_new_match_still_is(): void
    {
        TelegramSubscriber::query()->create(['chat_id' => '42']);
        TelegramIgnoredMatch::query()->create(['match_id' => '123', 'ignored_by' => '42']);
        $match = ['id' => '123', 'start' => now()->addMinutes(5)->toIso8601String(), 'home' => 'A', 'away' => 'B', 'tournament' => 'Cup'];
        app(AutomaticMatches::class)->receive(['event' => 'schedule', 'matches' => [$match, array_replace($match, ['id' => '456'])]]);
        app(AutomaticMatches::class)->tick();
        $this->assertSame('456', TelegramWatch::query()->sole()->match_id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r): bool => $r['reply_markup']['inline_keyboard'][0][0]['callback_data'] === 'ignore:456');
    }

    public function test_direct_calculation_cannot_save_an_ignored_match(): void
    {
        $draft = $this->draft();
        app(WatcherControl::class)->ignore($draft->match_id, '42');
        $this->mock(\App\Services\GoogleSheets\RoshSheetService::class)->shouldNotReceive('isConfigured');
        $this->mock(\App\Services\Stratz\StratzService::class)->shouldNotReceive('getRoshFromHeroes');
        $this->expectException(\RuntimeException::class);
        app(DraftCalculation::class)->run($draft);
    }

    public function test_failed_reminder_retries_only_the_undelivered_chat(): void
    {
        TelegramSubscriber::query()->create(['chat_id' => '42']);
        TelegramSubscriber::query()->create(['chat_id' => '43']);
        $control = app(WatcherControl::class);
        $control->state();
        $this->travel(12)->hours();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'error_code' => 503])
            ->push(['ok' => true, 'result' => ['message_id' => 78]])
            ->push(['ok' => true, 'result' => ['message_id' => 79]])]);
        $control->remind();
        $control->remind();
        Http::assertSentCount(3);
        $this->assertSame(2, TelegramSubscriber::query()->whereNotNull('watcher_reminded_at')->count());
    }
}
