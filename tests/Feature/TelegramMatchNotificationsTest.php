<?php

namespace Tests\Feature;

use App\Models\TelegramWatch;
use App\Services\Telegram\MatchNotifications;
use App\Services\Telegram\WatcherControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramMatchNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.token' => 'test']);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);
        foreach (['42', '43'] as $chat) {
            TelegramWatch::query()->create(['chat_id' => $chat, 'match_id' => '123', 'active' => true, 'expires_at' => now()->addHour(), 'notification_messages' => ['77' => false]]);
        }
    }

    public function test_ignore_announces_to_both_chats_and_removes_all_buttons_once(): void
    {
        app(WatcherControl::class)->ignore('123', '42');
        $service = app(MatchNotifications::class);
        $service->flush();
        $service->flush();
        Http::assertSentCount(4);
        foreach (['42', '43'] as $chat) {
            Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === $chat && str_contains($r['text'], 'проигнорирована'));
            Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/editMessageText') && $r['chat_id'] === $chat && $r['reply_markup']['inline_keyboard'] === [] && ! str_contains($r['text'], '/stop'));
        }
        $this->assertSame(0, TelegramWatch::query()->where('ignore_update_pending', true)->count());
    }

    public function test_failed_edit_is_retried_without_duplicate_announcement(): void
    {
        TelegramWatch::query()->where('chat_id', '43')->delete();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => true, 'result' => ['message_id' => 900]])->push(['ok' => false, 'error_code' => 503])->push(['ok' => true, 'result' => true])]);
        app(WatcherControl::class)->ignore('123', '42');
        $service = app(MatchNotifications::class);
        $service->flush();
        $service->flush();
        Http::assertSentCount(2);
        $this->assertTrue(TelegramWatch::query()->sole()->ignore_update_pending);
        $this->travel(61)->seconds();
        $service->flush();
        Http::assertSentCount(3);
        $this->assertFalse(TelegramWatch::query()->sole()->ignore_update_pending);
    }

    public function test_old_clicked_message_is_registered_and_updated_even_if_already_ignored(): void
    {
        app(WatcherControl::class)->ignore('123', '42');
        $service = app(MatchNotifications::class);
        $service->flush();
        $service->rememberClicked('42', '123', '88');
        $service->flush('123');
        Http::assertSentCount(5);
        Http::assertSent(fn ($r): bool => ($r['message_id'] ?? null) === '88' && $r['reply_markup']['inline_keyboard'] === []);
    }

    public function test_waiting_messages_are_remembered_and_late_messages_are_suppressed(): void
    {
        $service = app(MatchNotifications::class);
        $service->send('42', '123', 'Ожидаем составы.', [[['text' => 'Игнорировать игру', 'callback_data' => 'ignore:123']]]);
        $this->assertArrayHasKey('900', TelegramWatch::query()->where('chat_id', '42')->first()->notification_messages);
        app(WatcherControl::class)->ignore('123', '42');
        $service->send('43', '123', 'Ожидаем составы.');
        Http::assertSentCount(1);
    }
}
