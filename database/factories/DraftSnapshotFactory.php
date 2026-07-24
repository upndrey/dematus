<?php

namespace Database\Factories;

use App\Models\DraftSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DraftSnapshot>
 */
class DraftSnapshotFactory extends Factory
{
    protected $model = DraftSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'manual',
            'captured_at' => fake()->dateTime(),
            'match_id' => fake()->optional()->numberBetween(8_000_000_000, 9_999_999_999),
            'dltv_url' => null,
            'tournament' => null,
            'radiant_team' => fake()->company(),
            'dire_team' => fake()->company(),
            'radiant_heroes' => [1, 2, 3, 4, 5],
            'dire_heroes' => [6, 7, 8, 9, 10],
            'bookmaker_odds_radiant' => null,
            'bookmaker_odds_dire' => null,
            'draft_payload' => [],
            'stratz_payload' => [],
            'feature_payload' => [],
            'sheet_payload' => null,
            'book_payload' => null,
            'result_payload' => null,
            'model_version' => 'dematus-rosh-v1',
        ];
    }
}
