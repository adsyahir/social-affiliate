<?php

namespace Database\Factories;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform' => SocialPlatform::Threads,
            'name' => '@'.fake()->userName(),
            'external_id' => (string) fake()->randomNumber(9, true),
            'access_token' => null,
            'token_expires_at' => null,
            'persona' => [
                'language' => 'English',
                'tone' => 'Friendly and practical',
                'audience' => 'Global shoppers',
                'disclosure' => 'Affiliate link: I may earn a small commission.',
                'sample_posts' => [],
            ],
        ];
    }
}
