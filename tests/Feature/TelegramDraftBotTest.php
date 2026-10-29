<?php

namespace Tests\Feature;

use App\Jobs\CalculateTelegramDraft;
use App\Models\TelegramDraft;
use App\Models\TelegramWatch;
use App\Services\Telegram\BetBoomDraftSource;
use App\Services\Telegram\DraftBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramDraftBotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.token' => 'test-token', 'telegram.owner_id' => '42', 'telegram.allowed_chats' => []]);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
        Queue::fake();
    }

    private function payload(): array
    {
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/betboom/map2.json')), true);
        $payload['receivedAt'] = now()->getTimestampMs();

        return $payload;
    }

    private function draft(): TelegramDraft
    {
        return app(DraftBot::class)->ingest('42', $this->payload());
    }

    private function press(TelegramDraft $draft, string $action, string $userId = '42', string $chatId = '42'): void
    {
        app(DraftBot::class)->callback(['id' => 'callback', 'from' => ['id' => $userId], 'message' => ['chat' => ['id' => $chatId], 'message_id' => 77], 'data' => 'd:'.$draft->id.':'.$draft->revision.':'.$action]);
    }

    public function test_full_draft_is_normalized_and_calculation_order_follows_positions(): void
    {
        $draft = $this->draft();
        $payload = app(BetBoomDraftSource::class)->calculationPayload($draft->players);
        $this->assertSame([21, 106, 155, 119, 50], $payload['radiant_heroes']);
        $this->assertSame([145, 16, 29, 30, 123], $payload['dire_heroes']);
        $this->assertSame('LEGION', $payload['radiant_team']);
        $this->assertSame('77', $draft->message_id);
        $this->press($draft, 'confirm');
        $this->press($draft, 'confirm');
        Queue::assertPushed(CalculateTelegramDraft::class, 1);
        $this->assertSame('queued', $draft->fresh()->status);
    }

    public function test_positions_swap_only_within_team_and_preserve_hero_player_links(): void
    {
        $draft = $this->draft();
        $before = $draft->players;
        $this->press($draft, 'set:0:1');
        $after = $draft->fresh()->players;
        $this->assertSame(array_column($before, 'hero_id'), array_column($after, 'hero_id'));
        $this->assertSame(array_column($before, 'id'), array_column($after, 'id'));
        $this->assertSame(1, $after[0]['position']);
        $this->assertTrue(app(BetBoomDraftSource::class)->complete($after, true));
        $this->press($draft, 'confirm');
        Queue::assertNothingPushed();
    }

    public function test_duplicate_historical_roles_require_manual_resolution(): void
    {
        $payload = $this->payload();
        $payload['players'][0]['role'] = $payload['players'][1]['role'];
        $draft = app(DraftBot::class)->ingest('42', $payload);
        $this->assertFalse(app(BetBoomDraftSource::class)->complete($draft->players, true));
        $this->press($draft, 'confirm');
        Queue::assertNothingPushed();
    }

    public function test_source_swap_invalidates_buttons_and_repeated_source_preserves_manual_positions(): void
    {
        $draft = $this->draft();
        $this->press($draft, 'set:0:1');
        $draft->refresh();
        app(DraftBot::class)->ingest('42', $this->payload());
        $this->assertSame($draft->players, $draft->fresh()->players);
        $payload = $this->payload();
        [$payload['players'][0]['hero'], $payload['players'][1]['hero']] = [$payload['players'][1]['hero'], $payload['players'][0]['hero']];
        app(DraftBot::class)->ingest('42', $payload);
        $this->assertGreaterThan($draft->revision, $draft->fresh()->revision);
        $this->press($draft, 'confirm');
        Queue::assertNothingPushed();
    }

    public function test_stale_or_cross_chat_or_unauthorized_confirmation_is_rejected(): void
    {
        $draft = $this->draft();
        $this->press($draft, 'confirm', '99');
        $this->press($draft, 'confirm', '42', '99');
        $draft->update(['seen_at' => now()->subSeconds(61)]);
        $this->press($draft, 'confirm');
        Queue::assertNothingPushed();
    }

    public function test_partial_and_unknown_hero_drafts_do_not_publish_ready_cards(): void
    {
        $payload = $this->payload();
        array_pop($payload['players']);
        $this->assertNull(app(DraftBot::class)->ingest('42', $payload));
        $payload = $this->payload();
        $payload['players'][0]['hero'] = 'unknown hero';
        $this->assertNull(app(DraftBot::class)->ingest('42', $payload));
        Http::assertNothingSent();
    }

    public function test_new_map_expires_old_confirmation_and_late_packets_are_ignored(): void
    {
        $draft = $this->draft();
        $payload = $this->payload();
        $payload['map'] = 3;
        app(DraftBot::class)->ingest('42', $payload);
        $this->assertSame('expired', $draft->fresh()->status);
        $this->press($draft, 'confirm');
        Queue::assertNothingPushed();
    }

    public function test_watch_accepts_betboom_links_and_stop_disables_subscription(): void
    {
        $bot = app(DraftBot::class);
        $message = ['chat' => ['id' => 42], 'from' => ['id' => 42], 'text' => '/watch https://betboom.ru/esport/dota-2/39442/6344550'];
        $bot->update(['message' => $message]);
        $this->assertSame('6344550', TelegramWatch::query()->sole()->match_id);
        $message['text'] = '/stop';
        $bot->update(['message' => $message]);
        $this->assertFalse(TelegramWatch::query()->sole()->active);
    }

    public function test_owner_can_enable_group_confirmations_but_other_users_cannot_enable_groups(): void
    {
        $bot = app(DraftBot::class);
        $bot->update(['message' => ['chat' => ['id' => '-10'], 'from' => ['id' => '99'], 'text' => '/allow']]);
        $this->assertFalse($bot->authorized('-10', '99'));
        $bot->update(['message' => ['chat' => ['id' => '-10'], 'from' => ['id' => '42'], 'text' => '/allow']]);
        $this->assertTrue($bot->authorized('-10', '99'));
    }

    public function test_change_while_queued_requires_a_new_confirmation(): void
    {
        $draft = $this->draft();
        $this->press($draft, 'confirm');
        $payload = $this->payload();
        [$payload['players'][0]['hero'], $payload['players'][1]['hero']] = [$payload['players'][1]['hero'], $payload['players'][0]['hero']];
        app(DraftBot::class)->ingest('42', $payload);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(2, $draft->fresh()->revision);
        $this->assertNull($draft->fresh()->confirmed_at);
    }

    public function test_unchanged_feed_does_not_overwrite_position_selection_menu(): void
    {
        $draft = $this->draft();
        $this->press($draft, 'edit:0');
        $hash = $draft->fresh()->message_hash;
        app(DraftBot::class)->ingest('42', $this->payload());
        $this->assertSame($hash, $draft->fresh()->message_hash);
        Http::assertSentCount(3);
    }

    public function test_source_change_after_calculation_does_not_rewrite_confirmed_players(): void
    {
        $draft = $this->draft();
        $draft->update(['status' => 'done']);
        $payload = $this->payload();
        [$payload['players'][0]['hero'], $payload['players'][1]['hero']] = [$payload['players'][1]['hero'], $payload['players'][0]['hero']];
        app(DraftBot::class)->ingest('42', $payload);
        $this->assertTrue($draft->fresh()->source_changed);
        $this->assertSame($draft->players, $draft->fresh()->players);
    }

    public function test_failed_message_delivery_is_retried_on_next_source_update(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false, 'error_code' => 503])->push(['ok' => true, 'result' => ['message_id' => 77]])]);
        try {
            $this->draft();
        } catch (\RuntimeException) {
        }
        $this->assertNull(TelegramDraft::query()->sole()->message_id);
        $this->draft();
        $this->assertSame('77', TelegramDraft::query()->sole()->message_id);
        $this->assertSame(1, TelegramDraft::query()->count());
    }

    public function test_collector_command_consumes_script_output_without_a_real_process(): void
    {
        $process = \Mockery::mock(\Illuminate\Process\InvokedProcess::class);
        $process->shouldReceive('latestOutput')->once()->andReturn(json_encode($this->payload())."\n");
        $process->shouldReceive('latestErrorOutput')->once()->andReturn('');
        $process->shouldReceive('stop')->once();
        \Illuminate\Support\Facades\Process::shouldReceive('forever')->once()->andReturnSelf();
        \Illuminate\Support\Facades\Process::shouldReceive('start')->once()->with([config('telegram.node_binary'), resource_path('js/betboom/watch.mjs'), '6344550'])->andReturn($process);
        TelegramWatch::factory()->create(['chat_id' => '42', 'match_id' => '6344550']);
        $this->artisan('telegram:watch --once')->assertSuccessful();
        $this->assertSame(10, count(TelegramDraft::query()->sole()->players));
    }

    public function test_collector_announces_matching_connection_once_before_draft_is_complete(): void
    {
        $payload = $this->payload();
        $payload['players'] = array_slice($payload['players'], 0, 1);
        $events = [
            ['event' => 'connected', 'matchId' => '999'],
            ['event' => 'warning', 'code' => 'source_unavailable'],
            ['event' => 'connected', 'matchId' => '6344550'],
            ['event' => 'connected', 'matchId' => '6344550'],
            $payload,
        ];
        $process = \Mockery::mock(\Illuminate\Process\InvokedProcess::class);
        $process->shouldReceive('latestOutput')->once()->andReturn(implode("\n", array_map(fn (array $event): string => json_encode($event), $events))."\n");
        $process->shouldReceive('latestErrorOutput')->once()->andReturn('');
        $process->shouldReceive('stop')->once();
        \Illuminate\Support\Facades\Process::shouldReceive('forever')->once()->andReturnSelf();
        \Illuminate\Support\Facades\Process::shouldReceive('start')->once()->andReturn($process);
        TelegramWatch::factory()->create(['chat_id' => '42', 'match_id' => '6344550']);

        $this->artisan('telegram:watch --once')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_starts_with($request['text'], 'Подключились к матчу 6344550.'));
        $this->assertSame(0, TelegramDraft::query()->count());
    }

    public function test_polling_command_persists_offset_after_processing_update(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/getUpdates' => Http::response(['ok' => true, 'result' => [['update_id' => 54, 'message' => ['chat' => ['id' => 42], 'from' => ['id' => 42], 'text' => '/start']]]]),
            '*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
        ]);
        $this->artisan('telegram:bot --once')->assertSuccessful();
        $this->assertSame(55, \Illuminate\Support\Facades\Cache::get('telegram:offset'));
        Http::assertSentCount(2);
    }

    public function test_older_source_packet_cannot_restore_an_old_assignment(): void
    {
        $draft = $this->draft();
        $payload = $this->payload();
        $payload['receivedAt'] -= 10000;
        [$payload['players'][0]['hero'], $payload['players'][1]['hero']] = [$payload['players'][1]['hero'], $payload['players'][0]['hero']];
        app(DraftBot::class)->ingest('42', $payload);
        $this->assertSame($draft->players, $draft->fresh()->players);
        $this->assertSame(1, $draft->fresh()->revision);
    }

    public function test_allowed_username_can_use_bot_without_granting_group_access(): void
    {
        config(['telegram.allowed_usernames' => ['peskoffartem']]);
        $bot = app(DraftBot::class);
        $this->assertTrue($bot->authorized('99', '99', 'PeskoffArtem'));
        $this->assertFalse($bot->authorized('99', '99', 'peskoffartem_fake'));
        $bot->update(['message' => ['chat' => ['id' => '-10'], 'from' => ['id' => '99', 'username' => 'peskoffartem'], 'text' => '/allow']]);
        $this->assertFalse($bot->authorized('-10', '100', 'someone_else'));
        $draft = $this->draft();
        $bot->callback(['id' => 'allowed', 'from' => ['id' => '99', 'username' => 'peskoffartem'], 'message' => ['chat' => ['id' => '42'], 'message_id' => 77], 'data' => 'd:'.$draft->id.':'.$draft->revision.':confirm']);
        Queue::assertPushed(CalculateTelegramDraft::class, 1);
    }
}
