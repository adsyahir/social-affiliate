<?php

namespace Tests\Feature\Affiliates;

use App\Affiliates\AffiliateProviderFactory;
use App\Affiliates\Exceptions\AffiliateApiException;
use App\Affiliates\Exceptions\MissingCredentialsException;
use App\Affiliates\Shopee\ShopeeProvider;
use App\Enums\AffiliateProgram;
use App\Enums\ProductSort;
use App\Models\AffiliateAccount;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeProviderTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://open-api.affiliate.shopee.com.my/graphql';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopee_affiliate.endpoint' => self::ENDPOINT]);
        Http::preventStrayRequests();
    }

    public function test_requests_are_signed_over_the_exact_body_sent(): void
    {
        $this->freezeSecond();
        Http::fake([self::ENDPOINT => Http::response(['data' => ['generateShortLink' => ['shortLink' => 'https://s.shopee.com.my/abc']]])]);

        $this->provider()->trackingLink($this->product(), ['threads2']);

        Http::assertSent(function (Request $request) {
            $timestamp = now()->timestamp;
            $signature = hash('sha256', 'app-123'.$timestamp.$request->body().'secret-xyz');

            return $request->method() === 'POST'
                && $request->hasHeader('Authorization', "SHA256 Credential=app-123, Timestamp={$timestamp}, Signature={$signature}")
                && $request->hasHeader('Content-Type', 'application/json');
        });
    }

    public function test_tracking_link_is_generated_from_the_product_url_with_sub_ids(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => ['generateShortLink' => ['shortLink' => 'https://s.shopee.com.my/abc']]])]);

        $link = $this->provider()->trackingLink($this->product(), ['threads2', 'camp 7!', '', 'a', 'b', 'c', 'd']);

        $this->assertSame('https://s.shopee.com.my/abc', $link);
        Http::assertSent(function (Request $request) {
            $query = $request['query'];

            return str_contains($query, 'generateShortLink')
                && str_contains($query, 'originUrl: "https://shopee.com.my/product/1/2"')
                // Only letters and digits survive, empties are dropped, and Shopee takes at most five.
                && str_contains($query, 'subIds: ["threads2","camp7","a","b","c"]');
        });
    }

    public function test_pasted_short_link_is_used_as_is_without_credentials(): void
    {
        Http::fake();
        $product = $this->product(['product_url' => null, 'affiliate_link' => 'https://s.shopee.com.my/pasted']);

        $link = $this->provider(credentials: null)->trackingLink($product, ['threads2']);

        $this->assertSame('https://s.shopee.com.my/pasted', $link);
        Http::assertNothingSent();
    }

    public function test_pasted_short_link_is_used_as_is_when_there_is_no_product_url(): void
    {
        Http::fake();
        $product = $this->product(['product_url' => null, 'affiliate_link' => 'https://shope.ee/xyz']);

        $this->assertSame('https://shope.ee/xyz', $this->provider()->trackingLink($product));
        Http::assertNothingSent();
    }

    public function test_untracked_link_without_credentials_is_refused(): void
    {
        $product = $this->product(['product_url' => null, 'affiliate_link' => 'https://shopee.com.my/product/1/2']);

        $this->expectException(MissingCredentialsException::class);

        $this->provider(credentials: null)->trackingLink($product);
    }

    public function test_search_maps_offers_and_escapes_the_keyword(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => ['productOfferV2' => [
            'nodes' => [[
                'itemId' => 22334455,
                'productName' => 'Powerbank 10000mAh',
                'priceMin' => '25.9',
                'priceMax' => '39',
                'commissionRate' => '0.07',
                'imageUrl' => 'https://cf.shopee.com.my/file/img1',
                'productLink' => 'https://shopee.com.my/product/111/22334455',
                'offerLink' => 'https://s.shopee.com.my/offer1',
                'ratingStar' => '4.85',
                'sales' => 10234,
                'shopName' => 'Gadget MY',
            ]],
            'pageInfo' => ['hasNextPage' => true],
        ]]])]);

        $offers = $this->provider()->searchProducts('power "bank"', 5, ProductSort::BestSelling);

        $this->assertCount(1, $offers);
        $offer = $offers[0];
        $this->assertSame('22334455', $offer->externalId);
        $this->assertSame('Powerbank 10000mAh', $offer->name);
        $this->assertSame('https://shopee.com.my/product/111/22334455', $offer->productUrl);
        $this->assertSame('https://s.shopee.com.my/offer1', $offer->affiliateUrl);
        $this->assertSame(25.9, $offer->priceMin);
        $this->assertSame(39.0, $offer->priceMax);
        $this->assertSame(0.07, $offer->commissionRate);
        $this->assertSame(4.85, $offer->rating);
        $this->assertSame(10234, $offer->sales);
        $this->assertSame('RM25.90 - RM39.00 | 4.9 stars | 10,234 sold | 7% commission | Shop: Gadget MY', $offer->summary());

        Http::assertSent(fn (Request $request) => str_contains($request['query'], 'keyword: "power \"bank\""')
            && str_contains($request['query'], 'sortType: 2')
            && str_contains($request['query'], 'limit: 5'));
    }

    public function test_search_needs_credentials(): void
    {
        $provider = $this->provider(credentials: null);

        $this->assertFalse($provider->canSearchProducts());
        $this->expectException(MissingCredentialsException::class);

        $provider->searchProducts('powerbank');
    }

    public function test_graphql_errors_become_exceptions_and_rate_limits_are_retryable(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['errors' => [['message' => 'Too many requests', 'extensions' => ['code' => 10030, 'message' => 'Trigger traffic limiting']]]])
            ->push(['errors' => [['message' => 'Invalid signature', 'extensions' => ['code' => 10020, 'message' => 'Identity Authentication Error']]]]),
        ]);

        try {
            $this->provider()->searchProducts('powerbank');
            $this->fail('Expected a rate limit exception.');
        } catch (AffiliateApiException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame(10030, $e->apiCode);
        }

        try {
            $this->provider()->searchProducts('powerbank');
            $this->fail('Expected an authentication exception.');
        } catch (AffiliateApiException $e) {
            $this->assertFalse($e->retryable);
            $this->assertSame(10020, $e->apiCode);
            $this->assertStringContainsString('Identity Authentication Error', $e->getMessage());
        }
    }

    public function test_server_errors_are_retryable(): void
    {
        Http::fake([self::ENDPOINT => Http::response('Bad gateway', 502)]);

        try {
            $this->provider()->searchProducts('powerbank');
            $this->fail('Expected an exception.');
        } catch (AffiliateApiException $e) {
            $this->assertTrue($e->retryable);
        }
    }

    public function test_unreachable_api_is_retryable(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        try {
            $this->provider()->searchProducts('powerbank');
            $this->fail('Expected an exception.');
        } catch (AffiliateApiException $e) {
            $this->assertTrue($e->retryable);
            $this->assertStringNotContainsString('secret-xyz', $e->getMessage());
        }
    }

    public function test_search_skips_malformed_items(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => ['productOfferV2' => ['nodes' => [
            ['itemId' => 1, 'productName' => 'No link'],
            ['itemId' => 2, 'productName' => 'Fine', 'productLink' => 'https://shopee.com.my/p/2'],
        ]]]])]);

        $offers = $this->provider()->searchProducts('x');

        $this->assertSame(['2'], array_map(fn ($offer) => $offer->externalId, $offers));
    }

    public function test_look_alike_short_links_are_not_trusted(): void
    {
        $product = $this->product(['product_url' => null, 'affiliate_link' => 'https://s.shopee.evil.example/x']);

        $this->expectException(MissingCredentialsException::class);

        $this->provider(credentials: null)->trackingLink($product);
    }

    public function test_it_recognises_shopee_urls(): void
    {
        $provider = $this->provider();

        $this->assertTrue($provider->supportsUrl('https://shopee.com.my/product/1/2'));
        $this->assertTrue($provider->supportsUrl('https://s.shopee.com.my/abc'));
        $this->assertTrue($provider->supportsUrl('https://shope.ee/abc'));
        $this->assertFalse($provider->supportsUrl('https://www.amazon.com/dp/B0'));
        $this->assertFalse($provider->supportsUrl('https://shopee.com.my.evil.example/x'));
        $this->assertFalse($provider->supportsUrl('not a url'));
    }

    public function test_factory_resolves_the_driver_for_the_account_program(): void
    {
        $account = AffiliateAccount::factory()->create(['program' => AffiliateProgram::Shopee]);

        $this->assertInstanceOf(ShopeeProvider::class, app(AffiliateProviderFactory::class)->make($account));
        $this->assertSame(
            ['app_id', 'app_secret'],
            array_keys(AffiliateProviderFactory::credentialFields(AffiliateProgram::Shopee)),
        );
    }

    /**
     * @param  array<string, string>|null  $credentials
     */
    private function provider(?array $credentials = ['app_id' => 'app-123', 'app_secret' => 'secret-xyz']): ShopeeProvider
    {
        $account = AffiliateAccount::factory()->create([
            'program' => AffiliateProgram::Shopee,
            'credentials' => $credentials,
        ]);

        return app(AffiliateProviderFactory::class)->make($account);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): Product
    {
        return Product::factory()->make([
            'product_url' => 'https://shopee.com.my/product/1/2',
            'affiliate_link' => 'https://s.shopee.com.my/pasted',
            ...$attributes,
        ]);
    }
}
