<?php

namespace Tests\Feature;

use App\Models\DraftSnapshot;
use App\Services\Stratz\StratzDataWindowCollector;
use App\Services\Stratz\StratzService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StratzDataWindowCollectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_collects_four_eight_and_twelve_week_buckets_for_a_snapshot(): void
    {
        $snapshot = DraftSnapshot::factory()->create([
            'stratz_payload' => [
                'request' => [
                    'analysis' => [
                        'source' => 'match_id',
                        'week' => 1770574943,
                        'bracketBasicIds' => 'DIVINE_IMMORTAL',
                        'heroIds' => [114, 25, 23, 79, 112, 70, 59, 39, 83, 37],
                        'dataWindows' => [
                            'active' => ['mode' => 'legacy_mixed'],
                        ],
                    ],
                ],
            ],
        ]);

        $stratzService = Mockery::mock(StratzService::class);
        $stratzService
            ->shouldReceive('collectRoshDataWindowBucketPayloads')
            ->twice()
            ->andReturnUsing(function (
                int $anchorWeek,
                array $windowWeeks,
                string $bracketBasicId,
                array $heroIds,
            ): array {
                $payloads = [];

                foreach (['heroes_meta_positions', 'hero_stats_by_time_bracket', 'synergy'] as $dataType) {
                    foreach ($windowWeeks as $currentWindowWeeks) {
                        $payloads[] = [
                            'data_type' => $dataType,
                            'window_weeks' => $currentWindowWeeks,
                            'raw_payload' => [
                                'data_type' => $dataType,
                                'window_weeks' => $currentWindowWeeks,
                                'bracket_basic_id' => $bracketBasicId,
                                'hero_ids' => $heroIds,
                            ],
                            'normalized_payload' => [
                                'window_weeks' => $currentWindowWeeks,
                                'week_timestamps' => array_map(
                                    static fn (int $index): int => $anchorWeek - (604800 * $index),
                                    range(0, $currentWindowWeeks - 1),
                                ),
                                'summary' => ['count' => $currentWindowWeeks],
                            ],
                        ];
                    }
                }

                return $payloads;
            });

        $this->app->instance(StratzService::class, $stratzService);

        $collector = $this->app->make(StratzDataWindowCollector::class);

        $firstRunBuckets = $collector->collect($snapshot);
        $secondRunBuckets = $collector->collect($snapshot);

        $this->assertCount(9, $firstRunBuckets);
        $this->assertCount(9, $secondRunBuckets);
        $this->assertSame(9, $snapshot->stratzDataBuckets()->count());
        $this->assertSame([4, 8, 12], $snapshot->stratzDataBuckets()
            ->where('data_type', 'heroes_meta_positions')
            ->orderBy('window_weeks')
            ->pluck('window_weeks')
            ->all());
        $this->assertSame(
            'stratz_window_collector',
            $snapshot->stratzDataBuckets()
                ->where('data_type', 'synergy')
                ->where('window_weeks', 12)
                ->firstOrFail()
                ->normalized_payload['collection_source'],
        );
        $this->assertTrue(
            $snapshot->stratzDataBuckets()
                ->where('data_type', 'synergy')
                ->where('window_weeks', 12)
                ->firstOrFail()
                ->normalized_payload['optimized_collection'],
        );
    }
}
