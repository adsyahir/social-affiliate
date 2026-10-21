<?php

namespace Tests\Feature\Content;

use App\Content\DraftWriter;
use App\Content\Exceptions\ContentInvalidException;
use App\Content\ManualContentParser;
use App\Enums\ContentSource;
use App\Enums\PostStatus;
use App\Models\AffiliateAccount;
use App\Models\Campaign;
use App\Models\Product;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesClaude;
use Tests\TestCase;

class ManualContentTest extends TestCase
{
    use FakesClaude;
    use RefreshDatabase;

    private const DISCLOSURE = 'Link affiliate ya, aku dapat komisen sikit.';

    public function test_a_whole_claude_answer_is_split_into_post_and_reply(): void
    {
        $text = app(ManualContentParser::class)->parse("POST:\nPowerbank ni padu.\nCas laju.\n\nREPLY:\nGrab sini {link}");

        $this->assertSame("Powerbank ni padu.\nCas laju.", $text->post);
        $this->assertSame('Grab sini {link}', $text->linkReply);
        $this->assertSame('manual', $text->model);
    }

    public function test_markdown_around_the_labels_is_tolerated(): void
    {
        $text = app(ManualContentParser::class)->parse("Here you go!\n```\n**POST:** Padu gila.\n**REPLY:** Sini: {link}\n```");

        $this->assertSame('Padu gila.', $text->post);
        $this->assertSame('Sini: {link}', $text->linkReply);
    }

    public function test_plain_text_is_the_post_and_the_reply_is_just_the_link(): void
    {
        $text = app(ManualContentParser::class)->parse('Siapa nak powerbank murah?');

        $this->assertSame('Siapa nak powerbank murah?', $text->post);
        $this->assertSame('{link}', $text->linkReply);
    }

    public function test_a_separate_reply_wins_over_the_parsed_one(): void
    {
        $text = app(ManualContentParser::class)->parse("POST:\nA\nREPLY:\nB {link}", reply: 'Mine: {link}');

        $this->assertSame('A', $text->post);
        $this->assertSame('Mine: {link}', $text->linkReply);
    }

    public function test_the_copy_prompt_has_the_persona_product_and_answer_format(): void
    {
        [$product, $account] = $this->setUpCampaign();

        $prompt = app(DraftWriter::class)->copyPrompt($product, $account);

        $this->assertStringContainsString('Malay (rojak)', $prompt);
        $this->assertStringContainsString($product->name, $prompt);
        $this->assertStringContainsString("POST:\n", $prompt);
        $this->assertStringContainsString("REPLY:\n", $prompt);
        $this->assertStringContainsString('{link}', $prompt);
    }

    public function test_pasted_text_becomes_a_draft_without_calling_the_api(): void
    {
        [$product, $account, $campaign] = $this->setUpCampaign();
        $this->fakeClaude([]);

        $post = app(DraftWriter::class)->createFromText($product, $account, "POST:\nPadu gila.\nREPLY:\nSini {link}");

        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame($campaign->id, $post->campaign_id);
        $this->assertSame('Padu gila.', $post->content);
        $this->assertSame("Sini https://s.shopee.com.my/pasted\n\n".self::DISCLOSURE, $post->link_reply_content);
        $this->assertSame('manual', $post->generation['source']);
        $this->assertCount(0, $this->claudeRequests());
    }

    public function test_a_reply_without_the_placeholder_gets_the_link_added(): void
    {
        [$product, $account] = $this->setUpCampaign();

        $post = app(DraftWriter::class)->createFromText($product, $account, 'Padu.', reply: 'Link kat bawah ni');

        $this->assertSame("Link kat bawah ni\nhttps://s.shopee.com.my/pasted\n\n".self::DISCLOSURE, $post->link_reply_content);
    }

    public function test_broken_rules_are_explained_and_nothing_is_saved(): void
    {
        [$product, $account] = $this->setUpCampaign();

        try {
            app(DraftWriter::class)->createFromText($product, $account, 'Beli kat shopee.com.my/abc', reply: 'Sini https://other.link/x');
            $this->fail('Expected the text to be refused.');
        } catch (ContentInvalidException $e) {
            $this->assertCount(2, $e->problems);
            $this->assertStringContainsString('link', $e->getMessage());
        }

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_personal_claims_in_your_own_text_are_a_warning_not_a_block(): void
    {
        [$product, $account] = $this->setUpCampaign();

        $post = app(DraftWriter::class)->createFromText($product, $account, 'Aku dah guna 2 minggu, padu.');

        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertCount(1, $post->generation['warnings']);
        $this->assertStringContainsString('personal use', $post->generation['warnings'][0]);
    }

    public function test_a_pending_post_is_filled_from_pasted_text(): void
    {
        [$product, $account, $campaign] = $this->setUpCampaign();
        $writer = app(DraftWriter::class);

        $pending = $writer->createPending($product, $account, $campaign);
        $this->assertSame(PostStatus::Pending, $pending->status);
        $this->assertSame('https://s.shopee.com.my/pasted', $pending->generation['link']);

        $post = $writer->fillFromText($pending, 'Padu!');

        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame('Padu!', $post->content);
        $this->assertStringContainsString('https://s.shopee.com.my/pasted', $post->link_reply_content);
    }

    public function test_accounts_default_to_ai(): void
    {
        $this->assertSame(ContentSource::Ai, SocialAccount::factory()->create()->fresh()->content_source);
    }

    /**
     * @return array{Product, SocialAccount, Campaign}
     */
    private function setUpCampaign(): array
    {
        $affiliate = AffiliateAccount::factory()->create();
        $account = SocialAccount::factory()->create(['persona' => [
            'language' => 'Malay (rojak)', 'tone' => 'Santai', 'audience' => 'Students', 'disclosure' => self::DISCLOSURE, 'sample_posts' => [],
        ]]);
        $campaign = Campaign::factory()->for($affiliate)->create();
        $campaign->socialAccounts()->attach($account);
        $product = Product::factory()->for($affiliate)->create(['affiliate_link' => 'https://s.shopee.com.my/pasted']);

        return [$product, $account, $campaign];
    }
}
