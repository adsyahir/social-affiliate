<?php

namespace Tests\Feature\Telegram;

use App\Content\DraftWriter;
use App\Enums\ContentSource;
use App\Enums\PostStatus;
use App\Enums\SocialPlatform;
use App\Models\AffiliateAccount;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Telegram\DraftMessage;
use App\Telegram\UpdateHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The affiliate disclosure is a per-post choice: a button on each draft adds or removes it.
 */
class DisclosureToggleTest extends TestCase
{
    use RefreshDatabase;

    private const TG = 'https://api.telegram.org/bot123:tok';

    private const DISCLOSURE = 'Link affiliate ya, aku dapat komisen sikit kalau korang beli.';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => '123:tok',
            'services.telegram.chat_id' => '555',
            'services.telegram.allowed_user_ids' => [],
            'services.telegram.api_base_url' => 'https://api.telegram.org',
        ]);
        Http::preventStrayRequests();
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => true])]);
    }

    public function test_a_draft_offers_to_add_the_disclosure_and_says_it_is_not_included(): void
    {
        $post = $this->draft();

        $this->assertStringNotContainsString(self::DISCLOSURE, $post->link_reply_content);
        $this->assertContains(['text' => '➕ Add disclosure', 'callback_data' => "disc:{$post->id}"], array_merge(...DraftMessage::keyboard($post)));
        $this->assertStringContainsString('Disclosure: not included', DraftMessage::text($post));
    }

    public function test_the_button_adds_the_disclosure_and_then_removes_it_again(): void
    {
        $post = $this->draft();

        $this->tap("disc:{$post->id}");
        $this->assertSame("Sini https://s.shopee.com.my/x\n\n".self::DISCLOSURE, $post->fresh()->link_reply_content);
        $this->assertContains(['text' => '➖ Remove disclosure', 'callback_data' => "disc:{$post->id}"], array_merge(...DraftMessage::keyboard($post->fresh())));
        // The message is updated in place, with the buttons.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText') && str_contains($r['text'], 'Disclosure: included') && $r['message_id'] === 50);

        $this->tap("disc:{$post->id}");
        $this->assertSame('Sini https://s.shopee.com.my/x', $post->fresh()->link_reply_content);
    }

    public function test_no_button_without_a_disclosure_text(): void
    {
        $post = $this->draft(disclosure: '');

        $this->assertSame([], array_filter(array_merge(...DraftMessage::keyboard($post)), fn (array $button): bool => str_starts_with($button['callback_data'], 'disc:')));
        $this->assertStringNotContainsString('Disclosure', DraftMessage::text($post));
    }

    public function test_an_approved_post_cannot_be_changed(): void
    {
        $post = $this->draft();
        $post->update(['status' => PostStatus::Approved]);

        $this->tap("disc:{$post->id}");

        $this->assertSame('Sini https://s.shopee.com.my/x', $post->fresh()->link_reply_content);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery') && str_contains($r['text'], 'approved'));
    }

    public function test_ai_drafts_get_the_button_too(): void
    {
        $post = $this->draft();
        $post->update(['generation' => ['source' => 'ai', 'link' => 'https://s.shopee.com.my/x']]);

        $buttons = array_column(array_merge(...DraftMessage::keyboard($post->fresh())), 'callback_data');

        $this->assertContains("disc:{$post->id}", $buttons);
        $this->assertContains("regen:{$post->id}", $buttons);
    }

    private function draft(string $disclosure = self::DISCLOSURE): Post
    {
        $affiliate = AffiliateAccount::factory()->create();
        $account = SocialAccount::factory()->create([
            'platform' => SocialPlatform::Threads, 'content_source' => ContentSource::Manual,
            'persona' => ['language' => 'Malay', 'tone' => 'Santai', 'audience' => 'Students', 'disclosure' => $disclosure, 'sample_posts' => []],
        ]);
        $campaign = Campaign::factory()->for($affiliate)->create();
        $campaign->socialAccounts()->attach($account);
        $product = Product::factory()->for($affiliate)->create(['affiliate_link' => 'https://s.shopee.com.my/x']);
        $post = app(DraftWriter::class)->createFromText($product, $account, "POST:\nPadu.\nREPLY:\nSini {link}");
        $post->update(['telegram_chat_id' => '555', 'telegram_message_id' => 50]);

        return $post->fresh();
    }

    private function tap(string $data): void
    {
        app(UpdateHandler::class)->handle(['update_id' => 1, 'callback_query' => [
            'id' => 'cb', 'from' => ['id' => 9, 'username' => 'adam'], 'message' => ['message_id' => 50, 'chat' => ['id' => 555]], 'data' => $data,
        ]]);
    }
}
