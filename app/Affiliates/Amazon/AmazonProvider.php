<?php

namespace App\Affiliates\Amazon;

use App\Affiliates\Contracts\AffiliateProvider;
use App\Affiliates\Exceptions\AffiliateException;
use App\Affiliates\Exceptions\MissingCredentialsException;
use App\Enums\ProductSort;
use App\Models\AffiliateAccount;
use App\Models\Product;

/**
 * Amazon Associates. Links are built from product pages with the account's store tag, which
 * needs no API. (PA-API 5 was retired in 2026; its successor, the Creators API, needs a sales
 * history before Amazon grants access, so catalogue search is not offered.)
 */
class AmazonProvider implements AffiliateProvider
{
    private const SHORT_LINK_HOSTS = ['amzn.to', 'amzn.eu', 'amzn.asia', 'a.co'];

    public function __construct(private readonly AffiliateAccount $account) {}

    public static function credentialFields(): array
    {
        // Only the store tag is needed, and it lives in the account's Tracking ID field.
        return [];
    }

    public function supportsUrl(string $url): bool
    {
        $host = $this->host($url);

        return $host !== null && (
            in_array($host, self::SHORT_LINK_HOSTS, true)
            || preg_match('/(^|\.)amazon\.(com|co\.[a-z]{2}|com\.[a-z]{2}|[a-z]{2})$/', $host) === 1
        );
    }

    /**
     * Amazon has no sub IDs; reporting by channel needs one tracking ID per account instead.
     */
    public function trackingLink(Product $product, array $subIds = []): string
    {
        $url = filled($product->product_url) ? $product->product_url : $product->affiliate_link;

        if (! $this->supportsUrl($url)) {
            throw new AffiliateException("\"{$product->name}\" does not have an Amazon link.");
        }

        // SiteStripe short links already carry the tag they were made with.
        if (in_array($this->host($url), self::SHORT_LINK_HOSTS, true)) {
            return $url;
        }

        $tag = trim((string) $this->account->tracking_id);

        if ($tag === '') {
            throw new MissingCredentialsException("Set the store tag (tracking ID, e.g. yourstore-20) on \"{$this->account->name}\".");
        }

        $parts = parse_url($url);
        // amazon.sg and www.amazon.sg are the same store; links use the www form.
        $host = preg_replace('/^amazon\./', 'www.amazon.', strtolower($parts['host']));

        // A product page becomes a clean /dp/ASIN link: no search or referral clutter.
        if (preg_match('~/(?:dp|gp/product|gp/aw/d)/([A-Z0-9]{10})(?:[/?]|$)~i', $parts['path'] ?? '', $match)) {
            return "https://{$host}/dp/".strtoupper($match[1]).'?tag='.rawurlencode($tag);
        }

        // Any other Amazon page keeps its query, with the tag set to ours.
        parse_str($parts['query'] ?? '', $query);
        $query['tag'] = $tag;

        return "https://{$host}".($parts['path'] ?? '/').'?'.http_build_query($query, '', '&', PHP_QUERY_RFC1738);
    }

    public function canSearchProducts(): bool
    {
        return false;
    }

    public function searchProducts(string $keyword, int $limit = 20, ProductSort $sort = ProductSort::Relevance): array
    {
        throw new AffiliateException('Amazon catalogue search is not available; add Amazon products by hand.');
    }

    private function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : null;
    }
}
