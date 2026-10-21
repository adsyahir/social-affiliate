<?php

namespace Tests\Feature\Affiliates;

use App\Affiliates\AffiliateProviderFactory;
use App\Affiliates\Data\ProductOffer;
use App\Affiliates\Exceptions\UnsupportedProgramException;
use App\Affiliates\ImportProductOffers;
use App\Enums\AffiliateProgram;
use App\Enums\ProductSort;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\AffiliateAccount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AffiliateAdminTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://open-api.affiliate.shopee.com.my/graphql';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopee_affiliate.endpoint' => self::ENDPOINT]);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
    }

    public function test_importing_offers_skips_products_already_imported(): void
    {
        $account = AffiliateAccount::factory()->create();
        $offer = new ProductOffer(externalId: '1', name: 'Powerbank', productUrl: 'https://shopee.com.my/p/1', affiliateUrl: 'https://s.shopee.com.my/a', priceMin: 25.9, currency: 'RM');

        $first = app(ImportProductOffers::class)->handle($account, [$offer]);
        $second = app(ImportProductOffers::class)->handle($account, [$offer, new ProductOffer('2', 'Cable', 'https://shopee.com.my/p/2')]);

        $this->assertSame(['created' => 1, 'skipped' => 0], $first);
        $this->assertSame(['created' => 1, 'skipped' => 1], $second);

        $product = Product::where('external_id', '1')->sole();
        $this->assertSame('https://s.shopee.com.my/a', $product->affiliate_link);
        $this->assertSame('https://shopee.com.my/p/1', $product->product_url);
        $this->assertSame('RM25.90', $product->notes);
        // Without an offer link, the product page is the fallback link.
        $this->assertSame('https://shopee.com.my/p/2', Product::where('external_id', '2')->value('affiliate_link'));
    }

    public function test_long_offer_names_are_trimmed_to_fit(): void
    {
        $account = AffiliateAccount::factory()->create();

        app(ImportProductOffers::class)->handle($account, [new ProductOffer('1', str_repeat('Powerbank ', 40), 'https://shopee.com.my/p/1')]);

        $this->assertLessThanOrEqual(255, mb_strlen(Product::sole()->name));
    }

    public function test_import_action_searches_the_catalogue_and_adds_products(): void
    {
        $account = $this->shopeeAccount();
        Http::fake([self::ENDPOINT => Http::response(['data' => ['productOfferV2' => ['nodes' => [
            ['itemId' => 9, 'productName' => 'Desk lamp', 'productLink' => 'https://shopee.com.my/p/9', 'offerLink' => 'https://s.shopee.com.my/9', 'priceMin' => '19', 'sales' => 50],
        ], 'pageInfo' => ['hasNextPage' => false]]]])]);

        Livewire::test(ListProducts::class)
            ->callAction('importOffers', [
                'affiliate_account_id' => $account->id,
                'keyword' => 'desk lamp',
                'sort' => ProductSort::HighestCommission,
                'limit' => 10,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Imported 1 product');

        $this->assertSame('Desk lamp', Product::sole()->name);
        Http::assertSent(fn (Request $request) => str_contains($request['query'], 'sortType: 5'));
    }

    public function test_import_action_reports_api_errors_and_keeps_the_modal_open(): void
    {
        $account = $this->shopeeAccount();
        Http::fake([self::ENDPOINT => Http::response(['errors' => [['message' => 'x', 'extensions' => ['code' => 10020, 'message' => 'Identity Authentication Error']]]])]);

        Livewire::test(ListProducts::class)
            ->callAction('importOffers', [
                'affiliate_account_id' => $account->id,
                'keyword' => 'lamp',
                'sort' => ProductSort::Relevance,
                'limit' => 5,
            ])
            ->assertActionHalted('importOffers')
            ->assertNotified('Import failed');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_import_only_offers_accounts_that_can_search(): void
    {
        $searchable = $this->shopeeAccount();
        $noCredentials = AffiliateAccount::factory()->create(['program' => AffiliateProgram::Shopee, 'credentials' => null]);
        $noDriver = AffiliateAccount::factory()->create(['program' => AffiliateProgram::TikTokShop]);

        Livewire::test(ListProducts::class)
            ->callAction('importOffers', [
                'affiliate_account_id' => $noCredentials->id,
                'keyword' => 'lamp',
                'sort' => ProductSort::Relevance,
                'limit' => 5,
            ])
            ->assertHasActionErrors(['affiliate_account_id' => 'in']);

        Livewire::test(ListProducts::class)
            ->callAction('importOffers', [
                'affiliate_account_id' => $noDriver->id,
                'keyword' => 'lamp',
                'sort' => ProductSort::Relevance,
                'limit' => 5,
            ])
            ->assertHasActionErrors(['affiliate_account_id' => 'in']);

        $this->assertNotNull($searchable);
    }

    public function test_preview_shows_the_tracking_link(): void
    {
        $product = Product::factory()->for($this->shopeeAccount())->create(['product_url' => 'https://shopee.com.my/p/1']);
        Http::fake([self::ENDPOINT => Http::response(['data' => ['generateShortLink' => ['shortLink' => 'https://s.shopee.com.my/preview1']]])]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('previewTrackingLink')
            ->assertNotified('Tracking link');

        Http::assertSent(fn (Request $request) => str_contains($request['query'], 'subIds: ["preview"]'));
    }

    public function test_preview_explains_why_there_is_no_link(): void
    {
        $tiktok = AffiliateAccount::factory()->create(['program' => AffiliateProgram::TikTokShop]);
        $product = Product::factory()->for($tiktok)->create();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('previewTrackingLink')
            ->assertNotified('No tracking link');
    }

    public function test_factory_refuses_programs_without_a_driver(): void
    {
        $this->expectException(UnsupportedProgramException::class);

        app(AffiliateProviderFactory::class)->make(AffiliateAccount::factory()->create(['program' => AffiliateProgram::TikTokShop]));
    }

    private function shopeeAccount(): AffiliateAccount
    {
        return AffiliateAccount::factory()->create([
            'program' => AffiliateProgram::Shopee,
            'credentials' => ['app_id' => 'app-123', 'app_secret' => 'secret-xyz'],
        ]);
    }
}
