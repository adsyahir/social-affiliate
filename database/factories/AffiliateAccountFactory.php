<?php

namespace Database\Factories;

use App\Enums\AffiliateProgram;
use App\Models\AffiliateAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AffiliateAccount>
 */
class AffiliateAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'program' => AffiliateProgram::Shopee,
            'name' => fake()->company(),
            'tracking_id' => fake()->bothify('aff-####'),
            'credentials' => null,
        ];
    }
}
