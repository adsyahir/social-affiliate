<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\AffiliateAccount;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'affiliate_account_id' => AffiliateAccount::factory(),
            'name' => fake()->words(3, true),
            'affiliate_link' => fake()->url(),
            'notes' => null,
            'image_url' => null,
            'status' => ProductStatus::Available,
            'used_personally' => false,
            'review_notes' => null,
        ];
    }

    /**
     * A product you bought and used, with your own notes for the review.
     */
    public function usedPersonally(string $reviewNotes = 'Used it for two weeks. Battery lasts all day.'): static
    {
        return $this->state(fn () => [
            'used_personally' => true,
            'review_notes' => $reviewNotes,
        ]);
    }
}
