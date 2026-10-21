<?php

namespace Tests\Feature\Affiliates;

use App\Affiliates\AffiliateProviderFactory;
use App\Affiliates\Amazon\AmazonProvider;
use App\Affiliates\Exceptions\AffiliateException;
use App\Affiliates\Exceptions\MissingCredentialsException;
use App\Enums\AffiliateProgram;
use App\Models\AffiliateAccount;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AmazonProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_product_pages_get_a_clean_link_with_the_store_tag(): void
    {
        $link = $this->provider()->trackingLink($this->product('https://www.amazon.com/Anker-Charger-Foldable/dp/B0B9XYZ123/ref=sr_1_3?keywords=charger&th=1'));

        $this->assertSame('https://www.amazon.com/dp/B0B9XYZ123?tag=mystore-20', $link);
    }

    public function test_other_marketplaces_and_url_shapes_keep_their_domain(): void
    {
        $provider = $this->provider();

        $this->assertSame('https://www.amazon.co.uk/dp/B0C1234567?tag=mystore-20', $provider->trackingLink($this->product('https://www.amazon.co.uk/gp/product/B0C1234567?psc=1')));
        $this->assertSame('https://www.amazon.sg/dp/B0D7654321?tag=mystore-20', $provider->trackingLink($this->product('https://amazon.sg/dp/B0D7654321')));
    }

    public function test_someone_elses_tag_is_replaced(): void
    {
        $link = $this->provider()->trackingLink($this->product('https://www.amazon.com/s?k=usb+hub&tag=otherguy-20'));

        $this->assertSame('https://www.amazon.com/s?k=usb+hub&tag=mystore-20', $link);
    }

    public function test_short_links_from_sitestripe_are_used_as_is(): void
    {
        $this->assertSame('https://amzn.to/3xYzAbc', $this->provider()->trackingLink($this->product('https://amzn.to/3xYzAbc')));
    }

    public function test_the_product_page_url_is_preferred_over_the_pasted_link(): void
    {
        $product = $this->product('https://amzn.to/3xYzAbc', productUrl: 'https://www.amazon.com/dp/B0B9XYZ123');

        $this->assertSame('https://www.amazon.com/dp/B0B9XYZ123?tag=mystore-20', $this->provider()->trackingLink($product));
    }

    public function test_a_store_tag_is_required_for_full_links(): void
    {
        $this->expectException(MissingCredentialsException::class);
        $this->expectExceptionMessage('tracking ID');

        $this->provider(tag: null)->trackingLink($this->product('https://www.amazon.com/dp/B0B9XYZ123'));
    }

    public function test_links_that_are_not_amazon_are_refused(): void
    {
        $this->expectException(AffiliateException::class);

        $this->provider()->trackingLink($this->product('https://www.amazon.com.evil.example/dp/B0B9XYZ123'));
    }

    public function test_it_recognises_amazon_urls(): void
    {
        $provider = $this->provider();

        foreach (['https://www.amazon.com/dp/X', 'https://www.amazon.co.jp/dp/X', 'https://www.amazon.com.au/dp/X', 'https://amzn.to/x', 'https://amzn.eu/d/x', 'https://a.co/d/x'] as $url) {
            $this->assertTrue($provider->supportsUrl($url), $url);
        }

        foreach (['https://shopee.com.my/x', 'https://amazon.com.evil.example/x', 'https://notamazon.com/x', 'garbage'] as $url) {
            $this->assertFalse($provider->supportsUrl($url), $url);
        }
    }

    public function test_catalogue_search_is_not_available(): void
    {
        $provider = $this->provider();

        $this->assertFalse($provider->canSearchProducts());
        $this->assertSame([], AmazonProvider::credentialFields());

        $this->expectException(AffiliateException::class);
        $provider->searchProducts('charger');
    }

    public function test_the_factory_now_resolves_amazon(): void
    {
        $this->assertInstanceOf(AmazonProvider::class, $this->provider());
        $this->assertTrue(AffiliateProviderFactory::supports(AffiliateProgram::Amazon));
        $this->assertFalse(AffiliateProviderFactory::supports(AffiliateProgram::TikTokShop));
    }

    private function provider(?string $tag = 'mystore-20'): AmazonProvider
    {
        $account = AffiliateAccount::factory()->create(['program' => AffiliateProgram::Amazon, 'tracking_id' => $tag]);

        return app(AffiliateProviderFactory::class)->make($account);
    }

    private function product(string $link, ?string $productUrl = null): Product
    {
        return Product::factory()->make(['affiliate_link' => $link, 'product_url' => $productUrl]);
    }
}
