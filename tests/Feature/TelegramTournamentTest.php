<?php

namespace Tests\Feature;

use App\Jobs\CalculateTelegramDraft;
use App\Models\TelegramIgnoredTournament;
use App\Models\TelegramSubscriber;
use App\Models\TelegramWatch;
use App\Services\Telegram\AutomaticMatches;
use App\Services\Telegram\DraftBot;
use App\Services\Telegram\DraftCalculation;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\WatcherControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramTournamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.token' => 'test', 'telegram.owner_id' => '42', 'telegram.allowed_chats' => ['43']]);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
        Queue::fake();
        foreach (['42', '43'] as $chat) {
            TelegramWatch::query()->create(['chat_id' => $chat, 'match_id' => '6344550', 'tournament_id' => '39442', 'tournament_name' => 'Cup', 'active' => true, 'expires_at' => now()->addHour(), 'notification_messages' => ['66' => false]]);
        }
    }

    public function test_links_and_adjacent_button(): void
    {
        $text = 'Карта 2 · BetBoom 6344550';
        app(TelegramClient::class)->send('42', $text, [[['text' => 'Игнорировать игру', 'callback_data' => 'ignore:6344550']]]);
        Http::assertSent(function ($r) use ($text): bool {
            $entity = $r['entities'][0];

            return $entity['url'] === 'https://betboom.ru/esport/dota-2/39442/6344550'
                && mb_substr($text, $entity['offset'], $entity['length']) === '6344550'
                && $r['reply_markup']['inline_keyboard'][0][1]['callback_data'] === 'ignore_tournament:39442';
        });
        $payload = app(TelegramClient::class)->matchPresentation(['text' => "\u{1F600} BetBoom 6344550"]);
        $this->assertEquals(11, $payload['entities'][0]['offset']);
    }

    public function test_ignore_cancels_pending_job_and_notifies_both_chats(): void
    {
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/betboom/map2.json')), true);
        $payload['receivedAt'] = now()->getTimestampMs();
        $bot = app(DraftBot::class);
        $draft = $bot->ingest('42', $payload);
        $draft->update(['status' => 'queued']);
        $revision = $draft->revision;
        $bot->callback(['id' => 'ignore', 'from' => ['id' => '43'], 'message' => ['chat' => ['id' => '43'], 'message_id' => 66], 'data' => 'ignore_tournament:39442']);
        $this->assertSame('ignored', $draft->fresh()->status);
        $this->assertSame(0, TelegramWatch::query()->where('active', true)->count());
        $calculation = $this->mock(DraftCalculation::class);
        $calculation->shouldNotReceive('run');
        (new CalculateTelegramDraft($draft->id, $revision))->handle($calculation, $bot);
        $this->assertNull($bot->ingest('42', $payload));
        foreach (['42', '43'] as $chat) {
            Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/editMessageText') && $r['chat_id'] === $chat && str_contains($r['text'], 'Турнир Cup проигнорирован') && $r['reply_markup']['inline_keyboard'] === []);
        }
    }

    public function test_new_matches_and_manual_urls_are_rejected_but_other_tournaments_work(): void
    {
        app(WatcherControl::class)->ignoreTournament('39442', '42');
        TelegramSubscriber::query()->create(['chat_id' => '42']);
        $match = ['id' => '999', 'tournamentId' => '39442', 'start' => now()->toIso8601String(), 'home' => 'A', 'away' => 'B', 'tournament' => 'Cup'];
        app(AutomaticMatches::class)->receive(['event' => 'schedule', 'matches' => [$match, array_replace($match, ['id' => '888', 'tournamentId' => '123'])]]);
        app(AutomaticMatches::class)->tick();
        app(DraftBot::class)->update(['message' => ['chat' => ['id' => 42], 'from' => ['id' => 42], 'text' => 'https://betboom.ru/esport/dota-2/39442/999']]);
        $this->assertDatabaseMissing('telegram_watches', ['match_id' => '999']);
        $this->assertDatabaseHas('telegram_watches', ['match_id' => '888', 'active' => true]);
    }

    public function test_preserves_results_and_rejects_unknown_or_unauthorized_callbacks(): void
    {
        $bot = app(DraftBot::class);
        foreach ([['42', '999'], ['99', '39442']] as [$chat, $tournament]) {
            $bot->callback(['id' => 'bad', 'from' => ['id' => $chat], 'message' => ['chat' => ['id' => $chat]], 'data' => 'ignore_tournament:'.$tournament]);
        }
        $this->assertSame(0, TelegramIgnoredTournament::query()->count());
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/betboom/map2.json')), true);
        $payload['receivedAt'] = now()->getTimestampMs();
        $draft = $bot->ingest('42', $payload);
        $draft->update(['status' => 'done', 'sheet_result' => ['row' => 1403]]);
        app(WatcherControl::class)->ignoreTournament('39442', '42');
        $this->assertSame('done', $draft->fresh()->status);
        $this->assertSame(['row' => 1403], $draft->fresh()->sheet_result);
        $this->assertTrue(app(WatcherControl::class)->ignored($draft->match_id));
    }
}
