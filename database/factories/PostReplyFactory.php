<?php

namespace Database\Factories;

use App\Enums\ReplyStatus;
use App\Models\Post;
use App\Models\PostReply;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostReply>
 */
class PostReplyFactory extends Factory
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
            'external_reply_id' => (string) fake()->unique()->randomNumber(9, true),
            'author_username' => fake()->userName(),
            'text' => fake()->sentence(),
            'category' => null,
            'status' => ReplyStatus::New,
        ];
    }
}
