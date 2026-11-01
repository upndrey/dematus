<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TelegramDraft>
 */
class TelegramDraftFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_id' => '42', 'match_id' => (string) fake()->unique()->numberBetween(1000000, 9999999), 'map_number' => 1, 'fingerprint' => 'fixture', 'players' => [], 'source_payload' => [], 'seen_at' => now(), 'status' => 'draft', 'revision' => 1,
        ];
    }
}
