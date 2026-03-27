<?php

namespace Tests\Feature;

use App\Services\Liquipedia\LiquipediaService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StratzCreateTeamRosterCommandTest extends TestCase
{
    private string $teamRostersPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamRostersPath = storage_path('framework/testing/stratz-team-rosters-command.json');

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

    public function test_it_creates_a_saved_team_roster_from_liquipedia_nicknames(): void
    {
        $this->bindLiquipediaResponses([
            'timado' => [$this->player(97658618, 'Timado', "Enzo Gianoli O'Connor", ['Yamato'])],
            'abed' => [$this->player(154715080, 'Abed', 'Abed Yusop', ['Abed Azel'])],
            'saberlight' => [$this->player(126212866, 'SabeRLight-', 'Jonas Volek', ['Saberlight'])],
            'tims' => [$this->player(155494381, 'TIMS', 'Timothy Randrup', ['Tims'])],
            'whitemon' => [$this->player(126172296, 'Whitemon', 'Matthew Filemon', ['Whitemon'])],
        ]);

        $this->artisan('app:stratz-team-roster:create', [
            'name' => 'OG',
            'carry' => 'Timado',
            'mid' => 'Abed',
            'offlane' => 'SaberLight',
            'support4' => 'Tims',
            'support5' => 'Whitemon',
        ])
            ->expectsOutputToContain('Created saved roster [OG] (og).')
            ->expectsOutputToContain('Timado')
            ->expectsOutputToContain('SabeRLight-')
            ->assertSuccessful();

        $savedPayload = json_decode((string) File::get($this->teamRostersPath), true);

        $this->assertSame('OG', data_get($savedPayload, 'teams.0.name'));
        $this->assertSame(97658618, data_get($savedPayload, 'teams.0.players.0.steam_account_id'));
        $this->assertSame('SabeRLight-', data_get($savedPayload, 'teams.0.players.2.pro_name'));
        $this->assertSame('TIMS', data_get($savedPayload, 'teams.0.players.3.pro_name'));
    }

    public function test_it_requires_replace_option_to_overwrite_existing_team_name(): void
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

        $this->bindLiquipediaResponses([
            'timado' => [$this->player(97658618, 'Timado', "Enzo Gianoli O'Connor")],
            'abed' => [$this->player(154715080, 'Abed', 'Abed Yusop')],
            'saberlight' => [$this->player(126212866, 'SabeRLight-', 'Jonas Volek')],
            'tims' => [$this->player(155494381, 'TIMS', 'Timothy Randrup')],
            'whitemon' => [$this->player(126172296, 'Whitemon', 'Matthew Filemon')],
        ]);

        $this->artisan('app:stratz-team-roster:create', [
            'name' => 'OG',
            'carry' => 'Timado',
            'mid' => 'Abed',
            'offlane' => 'SaberLight',
            'support4' => 'Tims',
            'support5' => 'Whitemon',
        ])
            ->expectsOutputToContain('Saved team [OG] already exists. Re-run with --replace to update it.')
            ->assertFailed();
    }

    public function test_it_fails_when_liquipedia_cannot_resolve_a_player(): void
    {
        $this->bindLiquipediaResponses([
            'timado' => [$this->player(97658618, 'Timado', "Enzo Gianoli O'Connor")],
            'abed' => [$this->player(154715080, 'Abed', 'Abed Yusop')],
            'unknown' => [],
            'tims' => [$this->player(155494381, 'TIMS', 'Timothy Randrup')],
            'whitemon' => [$this->player(126172296, 'Whitemon', 'Matthew Filemon')],
        ]);

        $this->artisan('app:stratz-team-roster:create', [
            'name' => 'OG',
            'carry' => 'Timado',
            'mid' => 'Abed',
            'offlane' => 'Unknown',
            'support4' => 'Tims',
            'support5' => 'Whitemon',
        ])
            ->expectsOutputToContain('Liquipedia did not find a pro player for Offlane query [Unknown].')
            ->assertFailed();
    }

    /**
     * @param  array<string, list<array{
     *     steam_account_id:int,
     *     name:string,
     *     is_anonymous:bool,
     *     is_stratz_public:bool,
     *     last_match_date_time:int|null,
     *     season_rank:int|null,
     *     season_leaderboard_rank:int|null,
     *     pro_name:string|null,
     *     aliases:list<string>,
     *     team:array{id:int|null, name:string}|null
     * }>>  $responses
     */
    private function bindLiquipediaResponses(array $responses): void
    {
        $service = new class($responses) extends LiquipediaService
        {
            /**
             * @param  array<string, list<array{
             *     steam_account_id:int,
             *     name:string,
             *     is_anonymous:bool,
             *     is_stratz_public:bool,
             *     last_match_date_time:int|null,
             *     season_rank:int|null,
             *     season_leaderboard_rank:int|null,
             *     pro_name:string|null,
             *     aliases:list<string>,
             *     team:array{id:int|null, name:string}|null
             * }>>  $responses
             */
            public function __construct(private array $responses) {}

            public function searchProPlayers(string $query, int $take = 5): array
            {
                $normalizedQuery = mb_strtolower(trim($query));

                return $this->responses[$normalizedQuery] ?? [];
            }

            public function getProPlayers(): array
            {
                return [];
            }
        };

        $this->app->instance(LiquipediaService::class, $service);
    }

    /**
     * @param  list<string>  $aliases
     * @return array{
     *     steam_account_id:int,
     *     name:string,
     *     is_anonymous:bool,
     *     is_stratz_public:bool,
     *     last_match_date_time:int|null,
     *     season_rank:int|null,
     *     season_leaderboard_rank:int|null,
     *     pro_name:string|null,
     *     aliases:list<string>,
     *     team:array{id:int|null, name:string}|null
     * }
     */
    private function player(int $steamAccountId, string $proName, string $name, array $aliases = []): array
    {
        return [
            'steam_account_id' => $steamAccountId,
            'name' => $name,
            'is_anonymous' => false,
            'is_stratz_public' => false,
            'last_match_date_time' => null,
            'season_rank' => null,
            'season_leaderboard_rank' => null,
            'pro_name' => $proName,
            'aliases' => $aliases,
            'team' => null,
        ];
    }
}
