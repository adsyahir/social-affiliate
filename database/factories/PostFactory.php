<?php

namespace Database\Factories;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => null,
            'product_id' => null,
            'social_account_id' => SocialAccount::factory(),
            'content' => fake()->sentence(12),
            'link_reply_content' => null,
            'status' => PostStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Published,
            'external_post_id' => (string) fake()->randomNumber(9, true),
            'published_at' => now(),
        ]);
    }
}
