<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Exceptions\CampaignMismatchException;
use App\Models\AffiliateAccount;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\PostInsight;
use App\Models\Product;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DomainModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_secrets_are_encrypted_at_rest(): void
    {
        $affiliate = AffiliateAccount::factory()->create(['credentials' => ['app_secret' => 'shh']]);
        $social = SocialAccount::factory()->create(['access_token' => 'token-123']);

        $this->assertStringNotContainsString('shh', DB::table('affiliate_accounts')->value('credentials'));
        $this->assertStringNotContainsString('token-123', DB::table('social_accounts')->value('access_token'));

        $this->assertSame(['app_secret' => 'shh'], $affiliate->fresh()->credentials);
        $this->assertSame('token-123', $social->fresh()->access_token);
        $this->assertArrayNotHasKey('access_token', $social->toArray());
    }

    public function test_post_can_target_an_account_linked_to_its_campaign(): void
    {
        [$campaign, $account, $product] = $this->campaignWithAccountAndProduct();

        $post = Post::factory()->create([
            'campaign_id' => $campaign->id,
            'social_account_id' => $account->id,
            'product_id' => $product->id,
        ]);

        $this->assertTrue($post->exists);
    }

    public function test_post_cannot_target_an_account_outside_its_campaign(): void
    {
        [$campaign, , $product] = $this->campaignWithAccountAndProduct();
        $unrelated = SocialAccount::factory()->create();

        $this->expectException(CampaignMismatchException::class);

        Post::factory()->create([
            'campaign_id' => $campaign->id,
            'social_account_id' => $unrelated->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_post_cannot_use_a_product_from_another_affiliate_account(): void
    {
        [$campaign, $account] = $this->campaignWithAccountAndProduct();
        $otherProduct = Product::factory()->create();

        $this->expectException(CampaignMismatchException::class);

        Post::factory()->create([
            'campaign_id' => $campaign->id,
            'social_account_id' => $account->id,
            'product_id' => $otherProduct->id,
        ]);
    }

    public function test_moving_an_existing_post_to_an_unrelated_account_is_blocked(): void
    {
        [$campaign, $account, $product] = $this->campaignWithAccountAndProduct();
        $post = Post::factory()->create([
            'campaign_id' => $campaign->id,
            'social_account_id' => $account->id,
            'product_id' => $product->id,
        ]);

        $this->expectException(CampaignMismatchException::class);

        $post->update(['social_account_id' => SocialAccount::factory()->create()->id]);
    }

    public function test_available_products_are_scoped_to_the_campaign_in_order(): void
    {
        [$campaign, , $first] = $this->campaignWithAccountAndProduct();
        Product::factory()->for($campaign->affiliateAccount)->create(['status' => ProductStatus::Used]);
        $second = Product::factory()->for($campaign->affiliateAccount)->create();
        Product::factory()->create();

        $this->assertSame(
            [$first->id, $second->id],
            $campaign->availableProducts()->pluck('id')->all(),
        );
    }

    public function test_latest_insight_is_the_most_recent_fetch(): void
    {
        $post = Post::factory()->published()->create();
        PostInsight::factory()->for($post)->create(['views' => 10, 'fetched_at' => now()->subDay()]);
        PostInsight::factory()->for($post)->create(['views' => 50, 'fetched_at' => now()]);

        $this->assertSame(50, $post->latestInsight->views);
    }

    /**
     * @return array{Campaign, SocialAccount, Product}
     */
    private function campaignWithAccountAndProduct(): array
    {
        $campaign = Campaign::factory()->create();
        $account = SocialAccount::factory()->create();
        $campaign->socialAccounts()->attach($account);
        $product = Product::factory()->for($campaign->affiliateAccount)->create();

        return [$campaign, $account, $product];
    }
}
