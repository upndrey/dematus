<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StratzTeamRostersTest extends TestCase
{
    private string $teamRostersPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamRostersPath = storage_path('framework/testing/stratz-team-rosters.json');

        File::ensureDirectoryExists(dirname($this->teamRostersPath));
        File::put($this->teamRostersPath, json_encode([
            'teams' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        config()->set('services.stratz.team_rosters_path', $this->teamRostersPath);
    }

    protected function tearDown(): void
    {
        File::delete($this->teamRostersPath);

        parent::tearDown();
    }

    public function test_home_route_includes_saved_teams_in_inertia_props(): void
    {
        File::put($this->teamRostersPath, json_encode([
            'teams' => [
                [
                    'slug' => 'og',
                    'name' => 'OG',
                    'players' => array_fill(0, 5, null),
                    'updated_at' => '2026-03-27T10:00:00Z',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Stratz')
                ->has('heroes')
                ->has('savedTeams', 1)
                ->where('savedTeams.0.slug', 'og')
                ->where('savedTeams.0.name', 'OG'));
    }

    public function test_it_creates_a_team_roster(): void
    {
        $response = $this->postJson(route('stratz.teams.store'), [
            'name' => 'OG',
            'players' => [
                [
                    'steam_account_id' => 155494381,
                    'name' => 'Timothy John Randrup',
                    'pro_name' => 'TIMS',
                    'is_anonymous' => false,
                    'is_stratz_public' => false,
                    'team_name' => 'OG',
                ],
                null,
                null,
                null,
                null,
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('type', 'team_roster')
            ->assertJsonPath('data.slug', 'og')
            ->assertJsonPath('data.name', 'OG')
            ->assertJsonPath('data.players.0.pro_name', 'TIMS');

        $savedPayload = json_decode((string) File::get($this->teamRostersPath), true);

        $this->assertSame('OG', data_get($savedPayload, 'teams.0.name'));
        $this->assertSame(155494381, data_get($savedPayload, 'teams.0.players.0.steam_account_id'));
    }

    public function test_it_updates_a_team_roster(): void
    {
        File::put($this->teamRostersPath, json_encode([
            'teams' => [
                [
                    'slug' => 'og',
                    'name' => 'OG',
                    'players' => array_fill(0, 5, null),
                    'updated_at' => '2026-03-27T10:00:00Z',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $response = $this->patchJson(route('stratz.teams.update', ['teamRoster' => 'og']), [
            'name' => 'OG Seed',
            'players' => [
                null,
                [
                    'steam_account_id' => 126212866,
                    'name' => 'Jonas Volek',
                    'pro_name' => 'SabeRLight-',
                    'is_anonymous' => false,
                    'is_stratz_public' => false,
                    'team_name' => 'OG Seed',
                ],
                null,
                null,
                null,
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('type', 'team_roster')
            ->assertJsonPath('data.slug', 'og')
            ->assertJsonPath('data.name', 'OG Seed')
            ->assertJsonPath('data.players.1.pro_name', 'SabeRLight-');
    }

    public function test_it_deletes_a_team_roster(): void
    {
        File::put($this->teamRostersPath, json_encode([
            'teams' => [
                [
                    'slug' => 'og',
                    'name' => 'OG',
                    'players' => array_fill(0, 5, null),
                    'updated_at' => '2026-03-27T10:00:00Z',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $response = $this->deleteJson(route('stratz.teams.destroy', ['teamRoster' => 'og']));

        $response
            ->assertOk()
            ->assertJsonPath('type', 'team_roster_deleted')
            ->assertJsonPath('data.slug', 'og');

        $savedPayload = json_decode((string) File::get($this->teamRostersPath), true);

        $this->assertSame([], data_get($savedPayload, 'teams'));
    }

    public function test_it_rejects_duplicate_team_names(): void
    {
        File::put($this->teamRostersPath, json_encode([
            'teams' => [
                [
                    'slug' => 'og',
                    'name' => 'OG',
                    'players' => array_fill(0, 5, null),
                    'updated_at' => '2026-03-27T10:00:00Z',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $response = $this->postJson(route('stratz.teams.store'), [
            'name' => 'OG',
            'players' => array_fill(0, 5, null),
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }
}
