<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\PostInsight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostInsight>
 */
class PostInsightFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => Post::factory()->published(),
            'views' => fake()->numberBetween(0, 5000),
            'likes' => fake()->numberBetween(0, 300),
            'replies' => fake()->numberBetween(0, 40),
            'reposts' => fake()->numberBetween(0, 20),
            'fetched_at' => now(),
        ];
    }
}
