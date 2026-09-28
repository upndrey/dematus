<?php

namespace Tests\Feature;

use App\Models\DraftSnapshot;
use App\Models\StratzDataBucket;
use App\Services\DraftSnapshots\DraftSnapshotFeatureAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftSnapshotFeatureAggregatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_aggregates_window_features_from_stratz_buckets(): void
    {
        $snapshot = DraftSnapshot::factory()->create([
            'radiant_heroes' => [1, 2, 3, 4, 5],
            'dire_heroes' => [6, 7, 8, 9, 10],
        ]);

        StratzDataBucket::factory()->create([
            'draft_snapshot_id' => $snapshot->id,
            'bucket_key' => 'stratz_window_collector:match_id:heroes_meta_positions:4w',
            'data_type' => 'heroes_meta_positions',
            'window_weeks' => 4,
            'raw_payload' => [
                'weeks' => [
                    '1770000000' => $this->heroMetaPayload(),
                ],
            ],
        ]);

        StratzDataBucket::factory()->create([
            'draft_snapshot_id' => $snapshot->id,
            'bucket_key' => 'stratz_window_collector:match_id:hero_stats_by_time_bracket:4w',
            'data_type' => 'hero_stats_by_time_bracket',
            'window_weeks' => 4,
            'raw_payload' => [
                'weeks' => [
                    '1770000000' => $this->tempoPayload(),
                ],
            ],
        ]);

        $features = app(DraftSnapshotFeatureAggregator::class)->aggregate($snapshot);

        $this->assertSame(60.0, $features['radiant_4w_hero_winrate_avg']);
        $this->assertSame(40.0, $features['dire_4w_hero_winrate_avg']);
        $this->assertSame(20.0, $features['diff_4w_hero_winrate_avg']);
        $this->assertSame(500.0, $features['radiant_4w_hero_match_count']);
        $this->assertSame(500.0, $features['dire_4w_hero_match_count']);
        $this->assertSame(70.0, $features['radiant_4w_tempo20_winrate']);
        $this->assertSame(30.0, $features['dire_4w_tempo20_winrate']);
        $this->assertSame(40.0, $features['diff_4w_tempo20_winrate']);
        $this->assertNull($features['diff_8w_hero_winrate_avg']);
    }

    public function test_it_backfills_ml_features_onto_existing_snapshots(): void
    {
        $snapshot = DraftSnapshot::factory()->create([
            'radiant_heroes' => [1, 2, 3, 4, 5],
            'dire_heroes' => [6, 7, 8, 9, 10],
        ]);

        StratzDataBucket::factory()->create([
            'draft_snapshot_id' => $snapshot->id,
            'bucket_key' => 'stratz_window_collector:match_id:heroes_meta_positions:4w',
            'data_type' => 'heroes_meta_positions',
            'window_weeks' => 4,
            'raw_payload' => ['weeks' => ['1770000000' => $this->heroMetaPayload()]],
        ]);

        $this->artisan('snapshots:backfill-ml-features')->assertExitCode(0);

        $snapshot->refresh();

        $this->assertEquals(20.0, $snapshot->ml_feature_payload['diff_4w_hero_winrate_avg']);
        $this->assertNull($snapshot->ml_feature_payload['diff_8w_hero_winrate_avg']);
    }

    public function test_it_backfills_the_first_pick_side_from_saved_stratz_events(): void
    {
        $snapshot = DraftSnapshot::factory()->create([
            'match_id' => 8_921_931_974,
            'first_pick_side' => null,
            'stratz_payload' => [
                'match' => [
                    'pickBans' => [
                        ['isPick' => false, 'order' => 0, 'isRadiant' => true],
                        ['isPick' => true, 'order' => 3, 'isRadiant' => true],
                        ['isPick' => true, 'order' => 1, 'isRadiant' => false],
                    ],
                ],
            ],
        ]);

        $manualSnapshot = DraftSnapshot::factory()->create([
            'match_id' => null,
            'first_pick_side' => null,
        ]);

        $this->artisan('snapshots:backfill-first-picks')->assertExitCode(0);

        $this->assertSame('dire', $snapshot->fresh()->first_pick_side);
        $this->assertNull($manualSnapshot->fresh()->first_pick_side);
    }

    /**
     * @return array<string, list<array<string, int>>>
     */
    private function heroMetaPayload(): array
    {
        $payload = [];

        foreach (range(1, 5) as $positionId) {
            $payload['heroesPos_'.$positionId] = [
                [
                    'heroId' => $positionId,
                    'matchCount' => 100,
                    'winCount' => 60,
                ],
                [
                    'heroId' => $positionId + 5,
                    'matchCount' => 100,
                    'winCount' => 40,
                ],
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, list<array<string, int>>>
     */
    private function tempoPayload(): array
    {
        $payload = [];

        foreach (range(1, 5) as $positionId) {
            $payload['heroStatsByTime_'.$positionId] = [
                [
                    'heroId' => $positionId,
                    'time' => 20,
                    'matchCount' => 100,
                    'winCount' => 70,
                ],
                [
                    'heroId' => $positionId + 5,
                    'time' => 20,
                    'matchCount' => 100,
                    'winCount' => 30,
                ],
                [
                    'heroId' => $positionId,
                    'time' => 60,
                    'matchCount' => 100,
                    'winCount' => 65,
                ],
                [
                    'heroId' => $positionId + 5,
                    'time' => 60,
                    'matchCount' => 100,
                    'winCount' => 35,
                ],
            ];
        }

        return $payload;
    }
}
