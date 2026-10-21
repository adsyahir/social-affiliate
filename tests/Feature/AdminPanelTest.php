<?php

namespace Tests\Feature;

use App\Enums\AffiliateProgram;
use App\Enums\ProductStatus;
use App\Enums\SocialPlatform;
use App\Filament\Resources\AffiliateAccounts\Pages\CreateAffiliateAccount;
use App\Filament\Resources\AffiliateAccounts\Pages\EditAffiliateAccount;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\SocialAccounts\Pages\CreateSocialAccount;
use App\Filament\Resources\SocialAccounts\Pages\EditSocialAccount;
use App\Models\AffiliateAccount;
use App\Models\Campaign;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function adminPages(): array
    {
        return [
            'dashboard' => ['/admin'],
            'affiliate accounts' => ['/admin/affiliate-accounts'],
            'social accounts' => ['/admin/social-accounts'],
            'products' => ['/admin/products'],
            'campaigns' => ['/admin/campaigns'],
            'create social account' => ['/admin/social-accounts/create'],
            'create product' => ['/admin/products/create'],
            'create campaign' => ['/admin/campaigns/create'],
        ];
    }

    #[DataProvider('adminPages')]
    public function test_admin_pages_load(string $url): void
    {
        $this->seed();

        $this->get($url)->assertOk();
    }

    public function test_guests_are_sent_to_login(): void
    {
        auth()->logout();

        $this->get('/admin/products')->assertRedirect('/admin/login');
    }

    public function test_social_account_is_created_with_persona_and_encrypted_token(): void
    {
        Livewire::test(CreateSocialAccount::class)
            ->fillForm([
                'platform' => SocialPlatform::Threads,
                'name' => 'Threads #3',
                'access_token' => 'secret-token',
                'persona' => [
                    'language' => 'Malay',
                    'audience' => 'Students',
                    'tone' => 'Santai',
                    'disclosure' => 'Link affiliate ya.',
                    // A simple repeater keeps each item as [field => value] in the form, and saves a flat list.
                    'sample_posts' => [['text' => 'Murah gila ni.'], ['text' => 'Wajib ada.']],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $account = SocialAccount::sole();

        $this->assertSame('secret-token', $account->access_token);
        $this->assertSame('Malay', $account->persona['language']);
        $this->assertSame(['Murah gila ni.', 'Wajib ada.'], $account->persona['sample_posts']);
    }

    public function test_editing_a_social_account_keeps_the_token_when_left_blank(): void
    {
        $account = SocialAccount::factory()->create(['access_token' => 'keep-me']);

        Livewire::test(EditSocialAccount::class, ['record' => $account->getRouteKey()])
            ->assertSchemaStateSet(['access_token' => null])
            ->fillForm(['name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $account->refresh();
        $this->assertSame('Renamed', $account->name);
        $this->assertSame('keep-me', $account->access_token);
    }

    public function test_entering_a_token_on_edit_replaces_it_and_clears_the_field(): void
    {
        $account = SocialAccount::factory()->create(['access_token' => 'old-token']);

        Livewire::test(EditSocialAccount::class, ['record' => $account->getRouteKey()])
            ->fillForm(['access_token' => 'new-token'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSchemaStateSet(['access_token' => null]);

        $this->assertSame('new-token', $account->fresh()->access_token);
    }

    public function test_editing_an_affiliate_account_keeps_credentials_when_left_empty(): void
    {
        $account = AffiliateAccount::factory()->create(['credentials' => ['app_secret' => 'shh']]);

        Livewire::test(EditAffiliateAccount::class, ['record' => $account->getRouteKey()])
            ->fillForm(['name' => 'Renamed', 'program' => AffiliateProgram::Amazon])
            ->call('save')
            ->assertHasNoFormErrors();

        $account->refresh();
        $this->assertSame(AffiliateProgram::Amazon, $account->program);
        $this->assertSame(['app_secret' => 'shh'], $account->credentials);
    }

    public function test_affiliate_account_is_created_with_encrypted_credentials(): void
    {
        Livewire::test(CreateAffiliateAccount::class)
            ->fillForm([
                'program' => AffiliateProgram::Shopee,
                'name' => 'Shopee MY',
                'credential_rows' => [['key' => 'app_secret', 'value' => 'shh']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['app_secret' => 'shh'], AffiliateAccount::sole()->credentials);
    }

    public function test_entering_credentials_on_edit_replaces_them(): void
    {
        $account = AffiliateAccount::factory()->create(['credentials' => ['old' => '1', 'older' => '2']]);

        Livewire::test(EditAffiliateAccount::class, ['record' => $account->getRouteKey()])
            ->fillForm(['credential_rows' => [['key' => 'new', 'value' => '3']]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSchemaStateSet(['credential_rows' => []]);

        $this->assertSame(['new' => '3'], $account->fresh()->credentials);
    }

    public function test_review_notes_are_required_when_product_was_used_personally(): void
    {
        $affiliate = AffiliateAccount::factory()->create();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'affiliate_account_id' => $affiliate->id,
                'name' => 'Powerbank',
                'affiliate_link' => 'https://s.shopee.com.my/abc',
                'status' => ProductStatus::Available,
                'used_personally' => true,
                'review_notes' => '',
            ])
            ->call('create')
            ->assertHasFormErrors(['review_notes' => 'required']);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_product_link_must_be_a_url(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'affiliate_account_id' => AffiliateAccount::factory()->create()->id,
                'name' => 'Powerbank',
                'affiliate_link' => 'not a link',
            ])
            ->call('create')
            ->assertHasFormErrors(['affiliate_link' => 'url']);
    }

    public function test_products_table_filters_by_status(): void
    {
        $available = Product::factory()->create();
        $used = Product::factory()->create(['status' => ProductStatus::Used]);

        Livewire::test(ListProducts::class)
            ->filterTable('status', ProductStatus::Available->value)
            ->assertCanSeeTableRecords([$available])
            ->assertCanNotSeeTableRecords([$used]);
    }

    public function test_campaign_is_created_with_linked_social_accounts(): void
    {
        $affiliate = AffiliateAccount::factory()->create();
        $accounts = SocialAccount::factory()->count(2)->create();

        Livewire::test(CreateCampaign::class)
            ->fillForm([
                'name' => 'Barang murah',
                'affiliate_account_id' => $affiliate->id,
                'socialAccounts' => $accounts->pluck('id')->all(),
                'posts_per_day' => 3,
                'active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $campaign = Campaign::sole();
        $this->assertSame(3, $campaign->posts_per_day);
        $this->assertEqualsCanonicalizing($accounts->pluck('id')->all(), $campaign->socialAccounts->pluck('id')->all());
    }

    public function test_campaign_needs_at_least_one_social_account(): void
    {
        Livewire::test(CreateCampaign::class)
            ->fillForm([
                'name' => 'Empty',
                'affiliate_account_id' => AffiliateAccount::factory()->create()->id,
                'socialAccounts' => [],
                'posts_per_day' => 1,
            ])
            ->call('create')
            ->assertHasFormErrors(['socialAccounts' => 'required']);
    }

    public function test_campaigns_table_counts_products_left(): void
    {
        $campaign = Campaign::factory()->create();
        Product::factory()->count(2)->for($campaign->affiliateAccount)->create();
        Product::factory()->for($campaign->affiliateAccount)->create(['status' => ProductStatus::Used]);
        Product::factory()->create();

        Livewire::test(ListCampaigns::class)
            ->assertTableColumnStateSet('products_left', 2, $campaign);
    }

    public function test_campaign_can_be_paused_from_the_table(): void
    {
        $campaign = Campaign::factory()->create(['active' => true]);

        Livewire::test(ListCampaigns::class)
            ->call('updateTableColumnState', 'active', (string) $campaign->getKey(), false);

        $this->assertFalse($campaign->fresh()->active);
    }
}
