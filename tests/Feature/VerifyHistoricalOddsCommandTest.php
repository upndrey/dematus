<?php

namespace Tests\Feature;

use App\Models\DraftSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VerifyHistoricalOddsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_swaps_only_events_confirmed_as_reversed_by_the_control_bookie(): void
    {
        config()->set('services.odds_api.key', 'test-key');

        $snapshot = DraftSnapshot::factory()->create([
            'bookmaker_odds_radiant' => 1.280,
            'bookmaker_odds_dire' => 3.300,
            'book_payload' => [
                'provider' => 'odds-api.io',
                'bookmaker' => 'Stake',
                'event_id' => 4768886181,
            ],
        ]);

        Http::fake(function (Request $request) {
            $this->assertSame('4768886181', (string) $request['eventId']);
            $this->assertSame('1xbet,Stake', $request['bookmakers']);

            return Http::response([
                'home' => 'Vici Gaming',
                'away' => 'Team Yandex',
                'bookmakers' => [
                    '1xbet' => [[
                        'name' => 'ML',
                        'odds' => [['home' => '4.60', 'away' => '1.195']],
                    ]],
                    'Stake' => [[
                        'name' => 'ML',
                        'odds' => [['home' => '1.15', 'away' => '4.80']],
                    ]],
                ],
            ]);
        });

        $this->artisan('odds:verify-historical', ['--apply' => true])
            ->expectsTable(['Event ID', 'Snapshots', 'Orientation', 'Result'], [[4768886181, 1, 'reversed', 'saved']])
            ->assertExitCode(0);

        $snapshot->refresh();

        $this->assertSame('3.300', $snapshot->bookmaker_odds_radiant);
        $this->assertSame('1.280', $snapshot->bookmaker_odds_dire);
        $this->assertSame('reversed', data_get($snapshot->book_payload, 'verification.orientation'));
        $this->assertSame('1xbet', data_get($snapshot->book_payload, 'verification.control_bookie'));
    }

    public function test_it_preserves_events_without_a_control_line_and_marks_them_as_unverified(): void
    {
        config()->set('services.odds_api.key', 'test-key');

        $snapshot = DraftSnapshot::factory()->create([
            'bookmaker_odds_radiant' => 1.150,
            'bookmaker_odds_dire' => 4.800,
            'book_payload' => [
                'provider' => 'odds-api.io',
                'bookmaker' => 'Stake',
                'event_id' => 6154686627,
            ],
        ]);

        Http::fake([
            'https://api.odds-api.io/v3/historical/odds*' => Http::response([
                'bookmakers' => [
                    'Stake' => [[
                        'name' => 'ML',
                        'odds' => [['home' => '1.08', 'away' => '6.30']],
                    ]],
                ],
            ]),
        ]);

        $this->artisan('odds:verify-historical', ['--apply' => true])->assertExitCode(0);

        $snapshot->refresh();

        $this->assertSame('1.150', $snapshot->bookmaker_odds_radiant);
        $this->assertSame('4.800', $snapshot->bookmaker_odds_dire);
        $this->assertSame('unverified', data_get($snapshot->book_payload, 'verification.orientation'));
    }

    public function test_it_does_not_swap_a_previously_verified_reversed_snapshot_again(): void
    {
        config()->set('services.odds_api.key', 'test-key');

        $snapshot = DraftSnapshot::factory()->create([
            'bookmaker_odds_radiant' => 3.300,
            'bookmaker_odds_dire' => 1.280,
            'book_payload' => [
                'provider' => 'odds-api.io',
                'bookmaker' => 'Stake',
                'event_id' => 4768886181,
                'verification' => ['orientation' => 'reversed'],
            ],
        ]);

        Http::fake([
            'https://api.odds-api.io/v3/historical/odds*' => Http::response([
                'bookmakers' => [
                    '1xbet' => [['name' => 'ML', 'odds' => [['home' => '4.60', 'away' => '1.195']]]],
                    'Stake' => [['name' => 'ML', 'odds' => [['home' => '1.15', 'away' => '4.80']]]],
                ],
            ]),
        ]);

        $this->artisan('odds:verify-historical', ['--apply' => true])->assertExitCode(0);

        $snapshot->refresh();

        $this->assertSame('3.300', $snapshot->bookmaker_odds_radiant);
        $this->assertSame('1.280', $snapshot->bookmaker_odds_dire);
    }

    public function test_it_keeps_equal_odds_when_stake_has_no_team_orientation(): void
    {
        config()->set('services.odds_api.key', 'test-key');

        $snapshot = DraftSnapshot::factory()->create([
            'bookmaker_odds_radiant' => 1.850,
            'bookmaker_odds_dire' => 1.850,
            'book_payload' => [
                'provider' => 'odds-api.io',
                'bookmaker' => 'Stake',
                'event_id' => 6672287273,
            ],
        ]);

        Http::fake([
            'https://api.odds-api.io/v3/historical/odds*' => Http::response([
                'bookmakers' => [
                    '1xbet' => [['name' => 'ML', 'odds' => [['home' => '1.841', 'away' => '1.880']]]],
                    'Stake' => [['name' => 'ML', 'odds' => [['home' => '1.85', 'away' => '1.85']]]],
                ],
            ]),
        ]);

        $this->artisan('odds:verify-historical', ['--apply' => true])->assertExitCode(0);

        $snapshot->refresh();

        $this->assertSame('1.850', $snapshot->bookmaker_odds_radiant);
        $this->assertSame('1.850', $snapshot->bookmaker_odds_dire);
        $this->assertSame('equal', data_get($snapshot->book_payload, 'verification.orientation'));
    }
}
