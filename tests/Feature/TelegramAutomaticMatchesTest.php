<?php

namespace Tests\Feature;

use App\Models\TelegramDraft;
use App\Models\TelegramSubscriber;
use App\Models\TelegramWatch;
use App\Services\Telegram\AutomaticMatches;
use App\Services\Telegram\DraftBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramAutomaticMatchesTest extends TestCase
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

    public function test_start_opt_in_and_stop_opt_out(): void
    {
        $bot = app(DraftBot::class);
        $message = ['chat' => ['id' => 42], 'from' => ['id' => 42], 'text' => '/start'];
        $bot->update(['message' => $message]);
        $this->assertTrue(TelegramSubscriber::query()->sole()->active);
        $message['text'] = '/auto_off';
        $bot->update(['message' => $message]);
        $this->assertFalse(TelegramSubscriber::query()->sole()->active);
    }

    public function test_schedule_notifies_each_subscriber_once_and_respects_start_window(): void
    {
        TelegramSubscriber::query()->create(['chat_id' => '42']);
        TelegramSubscriber::query()->create(['chat_id' => '43']);
        $auto = app(AutomaticMatches::class);
        $match = ['id' => '123', 'start' => now()->addMinutes(11)->toIso8601String(), 'home' => 'A', 'away' => 'B', 'tournament' => 'Cup'];
        $auto->receive(['event' => 'schedule', 'matches' => [$match]]);
        $auto->tick();
        Http::assertNothingSent();
        $match['start'] = now()->addMinutes(9)->toIso8601String();
        $auto->receive(['event' => 'schedule', 'matches' => [$match]]);
        $auto->tick();
        $auto->tick();
        Http::assertSentCount(2);
        $this->assertSame(2, TelegramWatch::query()->count());
        $this->travel(4)->minutes();
        $auto->tick();
        Http::assertSentCount(2);
    }

    public function test_shared_card_confirms_once_from_either_chat(): void
    {
        foreach (['42', '43'] as $chat) {
            TelegramWatch::query()->create(['chat_id' => $chat, 'match_id' => '6344550', 'active' => true, 'expires_at' => now()->addHours(12)]);
        }
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/betboom/map2.json')), true);
        $payload['receivedAt'] = now()->getTimestampMs();
        $bot = app(DraftBot::class);
        $draft = $bot->ingest('42', $payload);
        $bot->ingest('43', $payload);
        $this->assertSame(1, TelegramDraft::query()->count());
        $this->assertSame(1, $draft->deliveries()->count());
        $players = $draft->players;
        foreach (['radiant', 'dire'] as $side) {
            $position = 1;
            foreach ($players as &$player) {
                if ($player['side'] === $side) {
                    $player['position'] = $position++;
                }
            }unset($player);
        }
        $draft->update(['players' => $players]);
        foreach (['43', '42'] as $chat) {
            $bot->callback(['id' => 'c'.$chat, 'from' => ['id' => $chat], 'message' => ['chat' => ['id' => $chat], 'message_id' => 77], 'data' => 'd:'.$draft->id.':'.$draft->revision.':confirm']);
        }
        Queue::assertPushed(\App\Jobs\CalculateTelegramDraft::class, 1);
        $this->assertSame('43', $draft->fresh()->confirmed_by);
    }
}
