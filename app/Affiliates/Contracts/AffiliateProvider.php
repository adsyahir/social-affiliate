<?php

namespace App\Affiliates\Contracts;

use App\Affiliates\Data\ProductOffer;
use App\Affiliates\Exceptions\AffiliateApiException;
use App\Affiliates\Exceptions\MissingCredentialsException;
use App\Enums\ProductSort;
use App\Models\Product;

/**
 * One driver per affiliate program, bound to a single affiliate account.
 * Each driver owns its program's link format and tracking.
 */
interface AffiliateProvider
{
    /**
     * Credentials the program's API needs, as name => description, for the admin form.
     *
     * @return array<string, string>
     */
    public static function credentialFields(): array;

    /**
     * Whether the URL is a product page or affiliate link of this program.
     */
    public function supportsUrl(string $url): bool;

    /**
     * The tracked link to post in the reply under a post.
     *
     * @param  list<string>  $subIds  Labels that tell the program which channel the click came from.
     *
     * @throws MissingCredentialsException When the link cannot be tracked without API credentials.
     * @throws AffiliateApiException
     */
    public function trackingLink(Product $product, array $subIds = []): string;

    public function canSearchProducts(): bool;

    /**
     * @return list<ProductOffer>
     *
     * @throws MissingCredentialsException
     * @throws AffiliateApiException
     */
    public function searchProducts(string $keyword, int $limit = 20, ProductSort $sort = ProductSort::Relevance): array;
}
