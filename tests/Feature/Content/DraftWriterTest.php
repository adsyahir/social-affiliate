<?php

namespace Tests\Feature\Content;

use App\Content\DraftWriter;
use App\Content\Exceptions\ContentGenerationException;
use App\Content\Exceptions\ContentRejectedException;
use App\Enums\AffiliateProgram;
use App\Enums\PostStatus;
use App\Models\AffiliateAccount;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\Product;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesClaude;
use Tests\TestCase;

class DraftWriterTest extends TestCase
{
    use FakesClaude;
    use RefreshDatabase;

    private const DISCLOSURE = 'Link affiliate ya, aku dapat komisen sikit.';

    public function test_draft_gets_the_tracked_link_and_disclosure_and_is_saved(): void
    {
        [$product, $account, $campaign] = $this->setUpCampaign();
        $this->fakeClaude([$this->claudeReply(['post' => 'Powerbank ni padu, 4.9 bintang.', 'link_reply' => 'Boleh grab sini: {link}'], usage: ['input_tokens' => 800, 'output_tokens' => 90])]);

        $post = app(DraftWriter::class)->create($product, $account);

        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame($campaign->id, $post->campaign_id);
        $this->assertSame('Powerbank ni padu, 4.9 bintang.', $post->content);
        $this->assertSame("Boleh grab sini: https://s.shopee.com.my/pasted\n\n".self::DISCLOSURE, $post->link_reply_content);
        $this->assertSame('claude-sonnet-5-5', $post->generation['model']);
        $this->assertSame(1, $post->generation['attempts']);
        $this->assertSame(800, $post->generation['usage']['input_tokens']);
        $this->assertSame('https://s.shopee.com.my/pasted', $post->generation['link']);
    }

    public function test_disclosure_is_not_added_twice(): void
    {
        [$product, $account] = $this->setUpCampaign();
        $this->fakeClaude([$this->claudeReply(['post' => 'Best!', 'link_reply' => '{link} '.self::DISCLOSURE])]);

        $post = app(DraftWriter::class)->create($product, $account);

        $this->assertSame('https://s.shopee.com.my/pasted '.self::DISCLOSURE, $post->link_reply_content);
    }

    public function test_an_empty_disclosure_adds_nothing(): void
    {
        [$product, $account] = $this->setUpCampaign();
        $account->update(['persona' => [...$account->persona, 'disclosure' => '']]);
        $this->fakeClaude([$this->claudeReply(['post' => 'Best!', 'link_reply' => 'Sini: {link}'])]);

        $post = app(DraftWriter::class)->create($product, $account->fresh());

        $this->assertSame('Sini: https://s.shopee.com.my/pasted', $post->link_reply_content);
        // Nor is the model asked to write one.
        $this->assertStringNotContainsString('word for word', (string) $this->claudeRequests()[0]->getBody());
    }

    public function test_api_generated_links_are_tagged_with_the_account_and_campaign(): void
    {
        config(['services.shopee_affiliate.endpoint' => 'https://open-api.affiliate.shopee.com.my/graphql']);
        [$product, $account, $campaign] = $this->setUpCampaign(credentials: ['app_id' => 'a', 'app_secret' => 's'], productUrl: 'https://shopee.com.my/p/1');
        Http::fake(['*' => Http::response(['data' => ['generateShortLink' => ['shortLink' => 'https://s.shopee.com.my/tagged']]])]);
        $this->fakeClaude([$this->claudeReply(['post' => 'Best!', 'link_reply' => 'Here: {link}'])]);

        $post = app(DraftWriter::class)->create($product, $account);

        $this->assertStringContainsString('https://s.shopee.com.my/tagged', $post->link_reply_content);
        Http::assertSent(fn (Request $r) => str_contains($r['query'], "subIds: [\"threads{$account->id}\",\"c{$campaign->id}\"]"));
    }

    public function test_programs_without_a_driver_use_the_pasted_link(): void
    {
        [$product, $account] = $this->setUpCampaign(program: AffiliateProgram::TikTokShop, link: 'https://vt.tiktok.com/ZSabc123/');
        $this->fakeClaude([$this->claudeReply(['post' => 'Great earbuds.', 'link_reply' => 'Get them: {link}'])]);

        $post = app(DraftWriter::class)->create($product, $account);

        $this->assertStringContainsString('https://vt.tiktok.com/ZSabc123/', $post->link_reply_content);
    }

    public function test_a_broken_rule_gets_one_retry_with_the_problem_explained(): void
    {
        [$product, $account] = $this->setUpCampaign();
        $this->fakeClaude([
            $this->claudeReply(['post' => 'Cun gila, beli kat shopee.com.my/abc', 'link_reply' => 'Sini {link}'], usage: ['input_tokens' => 800, 'output_tokens' => 90]),
            $this->claudeReply(['post' => 'Cun gila, harga pun ok.', 'link_reply' => 'Sini {link}'], usage: ['input_tokens' => 900, 'output_tokens' => 80]),
        ]);

        $post = app(DraftWriter::class)->create($product, $account);

        $this->assertSame('Cun gila, harga pun ok.', $post->content);
        $this->assertSame(2, $post->generation['attempts']);
        $this->assertSame(1700, $post->generation['usage']['input_tokens']);
        $retry = json_decode((string) $this->claudeRequests()[1]->getBody(), true)['messages'];
        $this->assertCount(3, $retry);
        $this->assertStringContainsString('link', $retry[2]['content']);
    }

    public function test_a_draft_that_still_breaks_the_rules_is_rejected_and_not_saved(): void
    {
        [$product, $account] = $this->setUpCampaign();
        $bad = ['post' => 'Grab at https://s.shopee.com.my/x', 'link_reply' => 'No placeholder here'];
        $this->fakeClaude([$this->claudeReply($bad), $this->claudeReply($bad)]);

        try {
            app(DraftWriter::class)->create($product, $account);
            $this->fail('Expected the draft to be rejected.');
        } catch (ContentRejectedException $e) {
            $this->assertNotEmpty($e->problems);
        }

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_spend_on_rejected_drafts_is_logged(): void
    {
        Log::spy();
        [$product, $account] = $this->setUpCampaign();
        $bad = ['post' => 'Grab at https://s.shopee.com.my/x', 'link_reply' => 'Sini {link}'];
        $this->fakeClaude([$this->claudeReply($bad, usage: ['input_tokens' => 800]), $this->claudeReply($bad, usage: ['input_tokens' => 900])]);

        try {
            app(DraftWriter::class)->create($product, $account);
        } catch (ContentRejectedException) {
            // Expected.
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Draft rejected by the posting rules'
            && $context['usage']['input_tokens'] === 1700);
    }

    public function test_first_person_claims_are_only_allowed_for_products_you_used(): void
    {
        [$product, $account] = $this->setUpCampaign();
        $claim = ['post' => 'Aku dah guna powerbank ni 2 minggu, memang tahan.', 'link_reply' => 'Sini {link}'];
        $this->fakeClaude([$this->claudeReply($claim), $this->claudeReply(['post' => 'Ramai suka powerbank ni.', 'link_reply' => 'Sini {link}'])]);

        $post = app(DraftWriter::class)->create($product, $account);
        $this->assertSame(2, $post->generation['attempts']);

        $product->update(['used_personally' => true, 'review_notes' => 'Guna 2 minggu, tahan lama.']);
        $this->fakeClaude([$this->claudeReply($claim)]);

        $post = app(DraftWriter::class)->create($product, $account);
        $this->assertSame(1, $post->generation['attempts']);
        $this->assertSame($claim['post'], $post->content);
    }

    public function test_accounts_outside_the_products_campaigns_are_refused_before_calling_the_model(): void
    {
        [$product] = $this->setUpCampaign();
        $outsider = SocialAccount::factory()->create();
        $this->fakeClaude([]);

        $this->expectException(ContentGenerationException::class);
        $this->expectExceptionMessage('campaign');

        try {
            app(DraftWriter::class)->create($product, $outsider);
        } finally {
            $this->assertCount(0, $this->claudeRequests());
        }
    }

    public function test_untrackable_links_fail_before_calling_the_model(): void
    {
        [$product, $account] = $this->setUpCampaign(link: 'https://shopee.com.my/product/1/2');
        $this->fakeClaude([]);

        try {
            app(DraftWriter::class)->create($product, $account);
            $this->fail('Expected an exception.');
        } catch (ContentGenerationException $e) {
            $this->assertStringContainsString('tracked Shopee link', $e->getMessage());
        }

        $this->assertCount(0, $this->claudeRequests());
    }

    public function test_regenerating_sends_the_previous_draft_and_feedback_and_updates_the_post(): void
    {
        [$product, $account] = $this->setUpCampaign();
        $this->fakeClaude([
            $this->claudeReply(['post' => 'First take.', 'link_reply' => 'Here {link}']),
            $this->claudeReply(['post' => 'Funnier take.', 'link_reply' => 'Grab it {link}']),
        ]);
        $writer = app(DraftWriter::class);
        $post = $writer->create($product, $account);
        $post->update(['status' => PostStatus::Rejected]);

        $updated = $writer->regenerate($post, 'Make it funnier');

        $this->assertSame($post->id, $updated->id);
        $this->assertSame(PostStatus::Draft, $updated->status);
        $this->assertSame('Funnier take.', $updated->content);
        $this->assertSame(1, $updated->generation['regenerations']);
        $messages = json_decode((string) $this->claudeRequests()[1]->getBody(), true)['messages'];
        $this->assertStringContainsString('First take.', $messages[1]['content']);
        $this->assertStringContainsString('{link}', $messages[1]['content']);
        $this->assertStringNotContainsString('s.shopee.com.my', $messages[1]['content']);
        $this->assertStringContainsString('Make it funnier', $messages[2]['content']);
        $this->assertDatabaseCount('posts', 1);
    }

    public function test_published_posts_cannot_be_regenerated(): void
    {
        [$product, $account, $campaign] = $this->setUpCampaign();
        $post = Post::factory()->published()->create(['campaign_id' => $campaign->id, 'product_id' => $product->id, 'social_account_id' => $account->id]);
        $this->fakeClaude([]);

        $this->expectException(ContentGenerationException::class);

        app(DraftWriter::class)->regenerate($post);
    }

    /**
     * @param  array<string, string>|null  $credentials
     * @return array{Product, SocialAccount, Campaign}
     */
    private function setUpCampaign(
        AffiliateProgram $program = AffiliateProgram::Shopee,
        string $link = 'https://s.shopee.com.my/pasted',
        ?array $credentials = null,
        ?string $productUrl = null,
    ): array {
        $affiliate = AffiliateAccount::factory()->create(['program' => $program, 'credentials' => $credentials]);
        $account = SocialAccount::factory()->create(['persona' => [
            'language' => 'Malay (rojak)', 'tone' => 'Santai', 'audience' => 'Students', 'disclosure' => self::DISCLOSURE, 'sample_posts' => [],
        ]]);
        $campaign = Campaign::factory()->for($affiliate)->create();
        $campaign->socialAccounts()->attach($account);
        $product = Product::factory()->for($affiliate)->create(['affiliate_link' => $link, 'product_url' => $productUrl]);

        return [$product, $account, $campaign];
    }
}
