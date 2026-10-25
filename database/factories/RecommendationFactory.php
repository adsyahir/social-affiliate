<?php

namespace Database\Factories;

use App\Models\Recommendation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recommendation>
 */
class RecommendationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'threads_url' => 'https://www.threads.com/@'.fake()->userName().'/post/'.fake()->bothify('???###???##'),
            'question' => fake()->sentence().'?',
            'reply_text' => fake()->sentence(),
            'status' => Recommendation::REPLIED,
            'telegram_chat_id' => '555',
            'telegram_message_id' => fake()->numberBetween(1, 100000),
        ];
    }
}
