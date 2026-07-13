<?php

namespace Tests\Feature;

use App\Models\DraftSnapshot;
use App\Models\StratzDataBucket;
use App\Services\DraftSnapshots\DraftSnapshotService;
use App\Services\Stratz\StratzDataWindowCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DraftSnapshotBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_draft_snapshot_summaries(): void
    {
        $snapshot = DraftSnapshot::factory()->create([
            'source' => 'match_id',
            'match_id' => 8893106015,
            'radiant_team' => 'Virtus.pro',
            'dire_team' => 'OG',
            'radiant_heroes' => [3, 96, 119, 138, 120],
            'dire_heroes' => [19, 128, 36, 66, 47],
            'stratz_payload' => ['match' => ['id' => 8893106015]],
            'feature_payload' => ['formatted' => ['dire_odds_1' => 13.6]],
            'sheet_payload' => ['sheet_title' => 'Dematus 3.0', 'row' => 254],
            'result_payload' => ['winner' => 'radiant'],
        ]);
        StratzDataBucket::factory()->count(3)->create([
            'draft_snapshot_id' => $snapshot->id,
        ]);

        $this
            ->withSession([config('static-auth.session_key') => true])
            ->getJson(route('stratz.snapshots'))
            ->assertOk()
            ->assertJsonPath('type', 'draft_snapshots')
            ->assertJsonPath('data.0.source', 'match_id')
            ->assertJsonPath('data.0.match_id', 8893106015)
            ->assertJsonPath('data.0.radiant_team', 'Virtus.pro')
            ->assertJsonPath('data.0.dire_team', 'OG')
            ->assertJsonPath('data.0.radiant_heroes_count', 5)
            ->assertJsonPath('data.0.dire_heroes_count', 5)
            ->assertJsonPath('data.0.sheet_title', 'Dematus 3.0')
            ->assertJsonPath('data.0.sheet_row', 254)
            ->assertJsonPath('data.0.stratz_data_buckets_count', 3)
            ->assertJsonPath('data.0.stratz_data_bucket_matrix.complete_4_8_12', false)
            ->assertJsonPath('data.0.has_stratz_payload', true);
    }

    public function test_it_shows_full_draft_snapshot_payload(): void
    {
        $snapshot = DraftSnapshot::factory()->create([
            'source' => 'live_dltv',
            'dltv_url' => 'https://dltv.org/matches/example',
            'draft_payload' => ['input' => ['source' => 'extension']],
            'stratz_payload' => ['analysis' => ['synergy' => []]],
            'feature_payload' => ['minute_table' => [['minute' => 20]]],
            'sheet_payload' => ['cells' => ['A2' => 'LIVE']],
            'result_payload' => ['winner' => null],
        ]);
        StratzDataBucket::factory()->create([
            'draft_snapshot_id' => $snapshot->id,
            'data_type' => 'synergy',
            'window_weeks' => 4,
            'week_timestamps' => [1770574943, 1769970143, 1769365343, 1768760543],
        ]);

        $this
            ->withSession([config('static-auth.session_key') => true])
            ->getJson(route('stratz.snapshots.show', $snapshot))
            ->assertOk()
            ->assertJsonPath('type', 'draft_snapshot')
            ->assertJsonPath('data.id', $snapshot->id)
            ->assertJsonPath('data.dltv_url', 'https://dltv.org/matches/example')
            ->assertJsonPath('data.draft_payload.input.source', 'extension')
            ->assertJsonPath('data.stratz_payload.analysis.synergy', [])
            ->assertJsonPath('data.feature_payload.minute_table.0.minute', 20)
            ->assertJsonPath('data.sheet_payload.cells.A2', 'LIVE')
            ->assertJsonPath('data.stratz_data_buckets.0.data_type', 'synergy')
            ->assertJsonPath('data.stratz_data_buckets.0.window_weeks', 4)
            ->assertJsonCount(4, 'data.stratz_data_buckets.0.week_timestamps');
    }

    public function test_it_collects_stratz_windows_for_a_snapshot(): void
    {
        $snapshot = DraftSnapshot::factory()->create([
            'stratz_payload' => [
                'request' => [
                    'analysis' => [
                        'week' => 1770574943,
                        'bracketBasicIds' => 'DIVINE_IMMORTAL',
                        'heroIds' => [114, 25, 23, 79, 112, 70, 59, 39, 83, 37],
                    ],
                ],
            ],
        ]);

        $collector = Mockery::mock(StratzDataWindowCollector::class);
        $collector
            ->shouldReceive('collect')
            ->once()
            ->with(Mockery::on(fn (DraftSnapshot $draftSnapshot): bool => $draftSnapshot->is($snapshot)))
            ->andReturnUsing(function (DraftSnapshot $draftSnapshot) {
                foreach (['heroes_meta_positions', 'hero_stats_by_time_bracket', 'synergy'] as $dataType) {
                    foreach ([4, 8, 12] as $windowWeeks) {
                        StratzDataBucket::factory()->create([
                            'draft_snapshot_id' => $draftSnapshot->id,
                            'bucket_key' => "stratz_window_collector:match_id:{$dataType}:{$windowWeeks}w",
                            'data_type' => $dataType,
                            'window_weeks' => $windowWeeks,
                            'normalized_payload' => ['optimized_collection' => true],
                        ]);
                    }
                }

                return $draftSnapshot->stratzDataBuckets()->get();
            });

        $this->app->instance(StratzDataWindowCollector::class, $collector);

        $this
            ->withSession([config('static-auth.session_key') => true])
            ->postJson(route('stratz.snapshots.collect-stratz-windows', $snapshot))
            ->assertOk()
            ->assertJsonPath('type', 'draft_snapshot')
            ->assertJsonPath('data.id', $snapshot->id)
            ->assertJsonPath('data.stratz_data_buckets_count', 9)
            ->assertJsonPath('data.stratz_data_bucket_matrix.complete_4_8_12', true);
    }

    public function test_it_does_not_recollect_complete_stratz_windows_for_a_snapshot(): void
    {
        $snapshot = DraftSnapshot::factory()->create();

        foreach (['heroes_meta_positions', 'hero_stats_by_time_bracket', 'synergy'] as $dataType) {
            foreach ([4, 8, 12] as $windowWeeks) {
                StratzDataBucket::factory()->create([
                    'draft_snapshot_id' => $snapshot->id,
                    'bucket_key' => "stratz_window_collector:match_id:{$dataType}:{$windowWeeks}w",
                    'data_type' => $dataType,
                    'window_weeks' => $windowWeeks,
                    'normalized_payload' => ['optimized_collection' => true],
                ]);
            }
        }

        $collector = Mockery::mock(StratzDataWindowCollector::class);
        $collector->shouldNotReceive('collect');

        $this->app->instance(StratzDataWindowCollector::class, $collector);

        $this
            ->withSession([config('static-auth.session_key') => true])
            ->postJson(route('stratz.snapshots.collect-stratz-windows', $snapshot))
            ->assertOk()
            ->assertJsonPath('type', 'draft_snapshot')
            ->assertJsonPath('data.id', $snapshot->id)
            ->assertJsonPath('data.stratz_data_buckets_count', 9)
            ->assertJsonPath('data.stratz_data_bucket_matrix.complete_4_8_12', true);

        $this->assertSame(9, $snapshot->stratzDataBuckets()->count());
    }

    public function test_it_backfills_stratz_data_buckets_from_existing_snapshot_payload(): void
    {
        $snapshot = DraftSnapshot::factory()->create([
            'stratz_payload' => [
                'request' => [
                    'analysis' => [
                        'week' => 1770574943,
                        'bracketBasicIds' => 'DIVINE_IMMORTAL',
                        'heroIds' => [114, 25, 23, 79, 112, 70, 59, 39, 83, 37],
                        'dataWindows' => [
                            'active' => ['mode' => 'legacy_mixed'],
                            'targets' => [
                                '4w' => [
                                    'weeks' => 4,
                                    'week_timestamps' => [1770574943, 1769970143, 1769365343, 1768760543],
                                ],
                            ],
                        ],
                        'operations' => [
                            ['key' => 'heroes_meta_positions', 'operationName' => 'HeroesMetaPositionsByWeek'],
                            ['key' => 'hero_stats_by_time_bracket', 'operationName' => 'GetHeroStatsByTime'],
                            ['key' => 'synergy', 'operationName' => 'Synergy', 'windowWeeks' => 4],
                        ],
                    ],
                ],
                'analysis' => [
                    'heroes_meta_positions' => ['heroes' => []],
                    'hero_stats_by_time_bracket' => ['heroStatsByTime_1' => []],
                    'synergy' => ['matchUp_Prev_Week_1' => []],
                ],
            ],
            'feature_payload' => [
                'analysis_summary' => [
                    'stratz_data_windows' => [
                        'active' => ['mode' => 'legacy_mixed'],
                        'targets' => [],
                    ],
                ],
            ],
        ]);

        app(DraftSnapshotService::class)->backfillStratzDataBuckets($snapshot);

        $this->assertSame(3, $snapshot->stratzDataBuckets()->count());
        $this->assertSame(4, $snapshot->stratzDataBuckets()->where('data_type', 'synergy')->firstOrFail()->window_weeks);
    }
}
