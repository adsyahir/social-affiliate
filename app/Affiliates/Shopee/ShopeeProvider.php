<?php

namespace App\Affiliates\Shopee;

use App\Affiliates\Contracts\AffiliateProvider;
use App\Affiliates\Data\ProductOffer;
use App\Affiliates\Exceptions\AffiliateApiException;
use App\Affiliates\Exceptions\MissingCredentialsException;
use App\Enums\ProductSort;
use App\Models\AffiliateAccount;
use App\Models\Product;

/**
 * Shopee Affiliate. With Open API credentials, links are generated per post and tagged with
 * sub IDs; without them, the short link pasted from the Shopee Affiliate dashboard is used.
 */
class ShopeeProvider implements AffiliateProvider
{
    private const SHORT_LINK_HOSTS = ['shope.ee', 'shp.ee'];

    private const MAX_SUB_IDS = 5;

    private const MAX_SEARCH_RESULTS = 50;

    public function __construct(private readonly AffiliateAccount $account) {}

    public static function credentialFields(): array
    {
        return [
            'app_id' => 'App ID from the Open API page of the Shopee Affiliate dashboard',
            'app_secret' => 'Secret shown with that App ID',
        ];
    }

    public function supportsUrl(string $url): bool
    {
        $host = $this->host($url);

        return $host !== null && (
            in_array($host, self::SHORT_LINK_HOSTS, true)
            || preg_match('/(^|\.)shopee\.(com\.[a-z]{2}|co\.[a-z]{2}|[a-z]{2})$/', $host) === 1
        );
    }

    public function trackingLink(Product $product, array $subIds = []): string
    {
        if (filled($product->product_url) && $this->hasCredentials() && $this->supportsUrl($product->product_url)) {
            return $this->generateShortLink($product->product_url, $subIds);
        }

        if ($this->isShortLink($product->affiliate_link)) {
            return $product->affiliate_link;
        }

        throw new MissingCredentialsException(
            "\"{$product->name}\" has no tracked Shopee link. Paste the short link from the Shopee Affiliate "
            ."dashboard, or add Open API credentials to \"{$this->account->name}\"."
        );
    }

    public function canSearchProducts(): bool
    {
        return $this->hasCredentials();
    }

    public function searchProducts(string $keyword, int $limit = 20, ProductSort $sort = ProductSort::Relevance): array
    {
        $limit = max(1, min($limit, self::MAX_SEARCH_RESULTS));

        $data = $this->client()->query(sprintf(
            'query { productOfferV2(keyword: %s, sortType: %d, page: 1, limit: %d) { '
            .'nodes { itemId productName priceMin priceMax commissionRate imageUrl productLink offerLink ratingStar sales shopName } '
            .'pageInfo { hasNextPage } } }',
            $this->literal($keyword),
            $this->sortType($sort),
            $limit,
        ));

        $currency = (string) config('services.shopee_affiliate.currency');

        // Items without an ID, name or product page cannot be imported or linked, so they are skipped.
        $nodes = array_filter(
            $data['productOfferV2']['nodes'] ?? [],
            fn (mixed $node): bool => is_array($node) && filled($node['itemId'] ?? null)
                && filled($node['productName'] ?? null) && filled($node['productLink'] ?? null),
        );

        return array_values(array_map(fn (array $node): ProductOffer => new ProductOffer(
            externalId: (string) $node['itemId'],
            name: (string) $node['productName'],
            productUrl: (string) $node['productLink'],
            affiliateUrl: $node['offerLink'] ?? null,
            imageUrl: $node['imageUrl'] ?? null,
            priceMin: isset($node['priceMin']) ? (float) $node['priceMin'] : null,
            priceMax: isset($node['priceMax']) ? (float) $node['priceMax'] : null,
            currency: $currency,
            commissionRate: isset($node['commissionRate']) ? (float) $node['commissionRate'] : null,
            rating: isset($node['ratingStar']) ? (float) $node['ratingStar'] : null,
            sales: isset($node['sales']) ? (int) $node['sales'] : null,
            shopName: $node['shopName'] ?? null,
        ), $nodes));
    }

    /**
     * @param  list<string>  $subIds
     */
    private function generateShortLink(string $url, array $subIds): string
    {
        $data = $this->client()->query(sprintf(
            'mutation { generateShortLink(input: {originUrl: %s, subIds: %s}) { shortLink } }',
            $this->literal($url),
            $this->literal($this->cleanSubIds($subIds)),
        ));

        $link = $data['generateShortLink']['shortLink'] ?? null;

        if (! is_string($link) || ! $this->supportsUrl($link)) {
            throw new AffiliateApiException('Shopee did not return a short link.');
        }

        return $link;
    }

    /**
     * Shopee accepts up to five alphanumeric sub IDs.
     *
     * @param  list<string>  $subIds
     * @return list<string>
     */
    private function cleanSubIds(array $subIds): array
    {
        $clean = array_map(fn (string $id): string => (string) preg_replace('/[^A-Za-z0-9]/', '', $id), $subIds);

        return array_slice(array_values(array_filter($clean, 'strlen')), 0, self::MAX_SUB_IDS);
    }

    private function sortType(ProductSort $sort): int
    {
        return match ($sort) {
            ProductSort::Relevance => 1,
            ProductSort::BestSelling => 2,
            ProductSort::HighestCommission => 5,
        };
    }

    /**
     * A GraphQL literal. JSON string and list syntax is valid GraphQL, so json_encode handles the escaping.
     */
    private function literal(string|array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function isShortLink(?string $url): bool
    {
        if ($url === null || ! $this->supportsUrl($url)) {
            return false;
        }

        $host = (string) $this->host($url);

        // A short link is only trackable with its code after the slash.
        return (in_array($host, self::SHORT_LINK_HOSTS, true) || str_starts_with($host, 's.shopee.'))
            && preg_match('~^/[A-Za-z0-9_-]+~', (string) parse_url($url, PHP_URL_PATH)) === 1;
    }

    private function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : null;
    }

    private function hasCredentials(): bool
    {
        return filled($this->account->credentials['app_id'] ?? null)
            && filled($this->account->credentials['app_secret'] ?? null);
    }

    private function client(): ShopeeClient
    {
        if (! $this->hasCredentials()) {
            throw new MissingCredentialsException("Add Shopee Open API credentials (app_id, app_secret) to \"{$this->account->name}\" first.");
        }

        return new ShopeeClient(
            (string) config('services.shopee_affiliate.endpoint'),
            (string) $this->account->credentials['app_id'],
            (string) $this->account->credentials['app_secret'],
        );
    }
}
