<?php

namespace App\Affiliates;

use App\Affiliates\Data\ProductOffer;
use App\Models\AffiliateAccount;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Adds catalogue offers to an affiliate account's products, skipping ones already imported.
 */
class ImportProductOffers
{
    /**
     * @param  list<ProductOffer>  $offers
     * @return array{created: int, skipped: int}
     */
    public function handle(AffiliateAccount $account, array $offers): array
    {
        $created = 0;

        foreach ($offers as $offer) {
            $product = Product::firstOrCreate(
                ['affiliate_account_id' => $account->id, 'external_id' => $offer->externalId],
                [
                    // Catalogue titles can be longer than the column.
                    'name' => Str::limit($offer->name, 250),
                    'affiliate_link' => $offer->affiliateUrl ?? $offer->productUrl,
                    'product_url' => $offer->productUrl,
                    'image_url' => $offer->imageUrl,
                    'notes' => $offer->summary(),
                ],
            );

            $created += (int) $product->wasRecentlyCreated;
        }

        return ['created' => $created, 'skipped' => count($offers) - $created];
    }
}
