<?php

namespace Tests\Feature;

use App\Services\DraftSnapshots\DraftSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TournamentSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_tournament_from_the_formatted_match_context(): void
    {
        $snapshot = app(DraftSnapshotService::class)->store(
            'batch_import',
            ['match_id' => 8902338515],
            [
                'formatted' => [
                    'match_id' => 8902338515,
                    'tournament' => 'Esports World Cup 2026',
                    'date_time' => 1784382596,
                ],
            ],
        );

        $this->assertDatabaseHas('draft_snapshots', [
            'id' => $snapshot->id,
            'tournament' => 'Esports World Cup 2026',
        ]);
    }
}
