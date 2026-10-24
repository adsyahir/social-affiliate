<?php

namespace Tests\Feature\Content;

use App\Content\DraftWriter;
use App\Content\PromptBuilder;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\SocialAccounts\Pages\EditSocialAccount;
use App\Models\AffiliateAccount;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\FakesClaude;
use Tests\TestCase;

class ContentAdminTest extends TestCase
{
    use FakesClaude;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_generate_draft_creates_a_post_and_opens_it(): void
    {
        [$product, $account] = $this->campaignSetup();
        $this->fakeClaude([$this->claudeReply(['post' => 'Lampu meja ni cun.', 'link_reply' => 'Sini: {link}'])]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('generateDraft', ['social_account_id' => $account->id])
            ->assertHasNoActionErrors()
            ->assertNotified('Draft written')
            ->assertRedirect(route('filament.admin.resources.posts.edit', Post::sole()));

        $this->assertSame('Lampu meja ni cun.', Post::sole()->content);
    }

    public function test_generate_draft_only_offers_accounts_in_the_products_campaigns(): void
    {
        [$product] = $this->campaignSetup();
        $outsider = SocialAccount::factory()->create();
        $this->fakeClaude([]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('generateDraft', ['social_account_id' => $outsider->id])
            ->assertHasActionErrors(['social_account_id' => 'in']);
    }

    public function test_generate_draft_explains_failures(): void
    {
        [$product, $account] = $this->campaignSetup();
        $this->fakeClaude([]);
        config(['services.anthropic.api_key' => null]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('generateDraft', ['social_account_id' => $account->id])
            ->assertNotified('Could not write a draft');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_drafts_are_listed_and_can_be_edited(): void
    {
        [$product, $account, $campaign] = $this->campaignSetup();
        $post = Post::factory()->create(['campaign_id' => $campaign->id, 'product_id' => $product->id, 'social_account_id' => $account->id, 'link_reply_content' => "Here https://s.shopee.com.my/x\n\nAffiliate link: I may earn a small commission."]);

        Livewire::test(ListPosts::class)->assertCanSeeTableRecords([$post]);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->fillForm(['content' => 'Edited by hand'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Edited by hand', $post->fresh()->content);
    }

    public function test_hand_edits_must_keep_the_posting_rules(): void
    {
        [$product, $account, $campaign] = $this->campaignSetup();
        $post = Post::factory()->create([
            'campaign_id' => $campaign->id, 'product_id' => $product->id, 'social_account_id' => $account->id,
            'link_reply_content' => "Here https://s.shopee.com.my/lamp\n\nAffiliate link: I may earn a small commission.",
            'generation' => ['link' => 'https://s.shopee.com.my/lamp'],
        ]);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->fillForm([
                'content' => 'Get it at shopee.com.my/lamp',
                'link_reply_content' => 'Here it is, no link and no disclosure',
            ])
            ->call('save')
            ->assertHasFormErrors(['content', 'link_reply_content']);

        $this->assertNotSame('Get it at shopee.com.my/lamp', $post->fresh()->content);
    }

    public function test_regenerate_writes_a_new_version_with_feedback(): void
    {
        [$product, $account] = $this->campaignSetup();
        $this->fakeClaude([
            $this->claudeReply(['post' => 'First.', 'link_reply' => 'Here {link}']),
            $this->claudeReply(['post' => 'Second, shorter.', 'link_reply' => 'Here {link}']),
        ]);
        $post = app(DraftWriter::class)->create($product, $account);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->callAction('regenerate', ['feedback' => 'Shorter'])
            ->assertNotified('New version written')
            ->assertSchemaStateSet(['content' => 'Second, shorter.']);

        $this->assertStringContainsString('Shorter', (string) $this->claudeRequests()[1]->getBody());
    }

    public function test_published_posts_are_read_only(): void
    {
        [$product, $account, $campaign] = $this->campaignSetup();
        $post = Post::factory()->published()->create(['campaign_id' => $campaign->id, 'product_id' => $product->id, 'social_account_id' => $account->id, 'link_reply_content' => 'x']);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->assertFormFieldDisabled('content')
            ->assertActionHidden('regenerate')
            ->assertActionHidden('delete');
    }

    public function test_the_disclosure_can_be_left_empty(): void
    {
        $account = SocialAccount::factory()->create();

        Livewire::test(EditSocialAccount::class, ['record' => $account->getRouteKey()])
            ->fillForm(['persona.disclosure' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull(PromptBuilder::disclosure($account->fresh()));
    }

    public function test_persona_disclosure_and_model_are_saved(): void
    {
        $account = SocialAccount::factory()->create();

        Livewire::test(EditSocialAccount::class, ['record' => $account->getRouteKey()])
            ->fillForm(['persona.disclosure' => 'Link affiliate ya.', 'ai_model' => 'claude-haiku-5-5'])
            ->call('save')
            ->assertHasNoFormErrors();

        $account->refresh();
        $this->assertSame('Link affiliate ya.', $account->persona['disclosure']);
        $this->assertSame('claude-haiku-5-5', $account->ai_model);
    }

    /**
     * @return array{Product, SocialAccount, Campaign}
     */
    private function campaignSetup(): array
    {
        $affiliate = AffiliateAccount::factory()->create();
        $account = SocialAccount::factory()->create();
        $campaign = Campaign::factory()->for($affiliate)->create();
        $campaign->socialAccounts()->attach($account);
        $product = Product::factory()->for($affiliate)->create(['affiliate_link' => 'https://s.shopee.com.my/lamp']);

        return [$product, $account, $campaign];
    }
}
