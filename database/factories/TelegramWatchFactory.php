<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TelegramWatch>
 */
class TelegramWatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_id' => '42', 'match_id' => (string) fake()->unique()->numberBetween(1000000, 9999999), 'active' => true, 'expires_at' => now()->addHours(12),
        ];
    }
}
