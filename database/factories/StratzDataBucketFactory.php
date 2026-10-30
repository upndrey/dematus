<?php

namespace Database\Factories;

use App\Models\DraftSnapshot;
use App\Models\StratzDataBucket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StratzDataBucket>
 */
class StratzDataBucketFactory extends Factory
{
    protected $model = StratzDataBucket::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'draft_snapshot_id' => DraftSnapshot::factory(),
            'bucket_key' => 'legacy_mixed:heroes_meta_positions:1w',
            'data_type' => 'heroes_meta_positions',
            'window_weeks' => 1,
            'anchor_week' => 1770574943,
            'week_timestamps' => [1770574943],
            'bracket_basic_id' => 'DIVINE_IMMORTAL',
            'hero_ids' => [1, 2, 3, 4, 5],
            'raw_payload' => ['heroes' => []],
            'normalized_payload' => ['summary' => []],
            'payload_hash' => hash('sha256', 'stratz-data-bucket'),
            'model_version' => 'dematus-rosh-v1',
        ];
    }
}
