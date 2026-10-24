<?php

namespace Tests\Feature\Content;

use App\Content\DraftWriter;
use App\Content\Exceptions\ContentGenerationException;
use App\Enums\ContentSource;
use App\Enums\PostStatus;
use App\Filament\Resources\Campaigns\Tables\CampaignsTable;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\SocialAccounts\Pages\EditSocialAccount;
use App\Filament\Widgets\PerformanceOverview;
use App\Jobs\GenerateDraft;
use App\Jobs\RegenerateDraft;
use App\Jobs\SyncApprovalMessage;
use App\Models\AffiliateAccount;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Models\User;
use App\Telegram\DraftMessage;
use App\Telegram\UpdateHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\FakesClaude;
use Tests\TestCase;

class ManualFlowTest extends TestCase
{
    use FakesClaude;
    use RefreshDatabase;

    private const TG = 'https://api.telegram.org/bot123:tok';

    private const ANSWER = "POST:\nPowerbank kecik tapi padu.\nREPLY:\nGrab sini {link}";

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
    }

    public function test_the_account_chooses_who_writes_and_hides_the_model_when_manual(): void
    {
        $this->actingAs(User::factory()->create());
        $account = SocialAccount::factory()->create();

        Livewire::test(EditSocialAccount::class, ['record' => $account->getRouteKey()])
            ->assertFormFieldVisible('ai_model')
            ->fillForm(['content_source' => ContentSource::Manual])
            ->assertFormFieldHidden('ai_model')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(ContentSource::Manual, $account->fresh()->content_source);
    }

    public function test_write_post_turns_pasted_text_into_a_draft(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());
        [$product, $account] = $this->setUpCampaign();
        $this->fakeClaude([]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('writePost', ['social_account_id' => $account->id, 'text' => self::ANSWER])
            ->assertHasNoActionErrors()
            ->assertNotified('Draft saved')
            ->assertRedirect(route('filament.admin.resources.posts.edit', Post::sole()));

        $this->assertSame('Powerbank kecik tapi padu.', Post::sole()->content);
        $this->assertCount(0, $this->claudeRequests());
        Queue::assertPushed(SyncApprovalMessage::class);
    }

    public function test_write_post_shows_the_prompt_to_copy_for_the_chosen_account(): void
    {
        $this->actingAs(User::factory()->create());
        [$product, $account] = $this->setUpCampaign();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction('writePost')
            ->setActionData(['social_account_id' => $account->id])
            ->assertMountedActionModalSee(['Malay (rojak)', 'REPLY:']);
    }

    public function test_write_post_explains_broken_rules_and_saves_nothing(): void
    {
        $this->actingAs(User::factory()->create());
        [$product, $account] = $this->setUpCampaign();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('writePost', ['social_account_id' => $account->id, 'text' => 'Beli kat shopee.com.my/abc'])
            ->assertActionHalted('writePost')
            ->assertNotified('Please fix the text');

        $this->assertDatabaseCount('posts', 0);
    }

    public function test_a_waiting_post_can_be_written_from_the_admin(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());
        [$product, $account, $campaign] = $this->setUpCampaign();
        $pending = app(DraftWriter::class)->createPending($product, $account, $campaign);

        Livewire::test(EditPost::class, ['record' => $pending->getRouteKey()])
            ->assertActionHidden('regenerate')
            ->callAction('writePost', ['text' => self::ANSWER])
            ->assertNotified('Draft saved');

        $this->assertSame(PostStatus::Draft, $pending->fresh()->status);
    }

    public function test_scheduled_slots_for_manual_accounts_ask_you_in_telegram(): void
    {
        [$product, $account, $campaign] = $this->setUpCampaign(ContentSource::Manual);
        $this->fakeClaude([]);
        Http::fake([self::TG.'/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 50]])]);

        GenerateDraft::dispatchSync($account, $product, $campaign);

        $post = Post::sole();
        $this->assertSame(PostStatus::Pending, $post->status);
        $this->assertSame(50, $post->telegram_message_id);
        $this->assertCount(0, $this->claudeRequests());
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'Reply to this message with your post')
            && str_contains($r['text'], '<pre>')
            && str_contains($r['text'], 'Malay (rojak)')
            && $r['reply_markup'] === ['inline_keyboard' => [[['text' => '⏭ Skip', 'callback_data' => "reject:{$post->id}"]]]]);
    }

    public function test_replying_with_text_fills_the_waiting_post(): void
    {
        [$product, $account, $campaign] = $this->setUpCampaign(ContentSource::Manual);
        $pending = app(DraftWriter::class)->createPending($product, $account, $campaign);
        $pending->update(['telegram_chat_id' => '555', 'telegram_message_id' => 50]);
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => ['message_id' => 51]])]);

        $this->reply(50, self::ANSWER);

        $post = $pending->fresh();
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame('Powerbank kecik tapi padu.', $post->content);
        // The draft and its buttons arrive under your reply, so you don't scroll back up for them.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && $r['reply_parameters']['message_id'] === 99
            && $r['reply_markup'] === ['inline_keyboard' => DraftMessage::keyboard($post)]
            && count(DraftMessage::keyboard($post)[0]) === 2);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText')
            && $r['message_id'] === 50 && str_contains($r['text'], 'continues below') && $r['reply_markup'] === ['inline_keyboard' => []]);
        $this->assertSame(51, $post->telegram_message_id);
    }

    public function test_a_reply_that_breaks_the_rules_is_answered_with_what_to_fix(): void
    {
        [$product, $account, $campaign] = $this->setUpCampaign(ContentSource::Manual);
        $pending = app(DraftWriter::class)->createPending($product, $account, $campaign);
        $pending->update(['telegram_chat_id' => '555', 'telegram_message_id' => 50]);
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $this->reply(50, 'Beli kat shopee.com.my/abc');

        $this->assertSame(PostStatus::Pending, $pending->fresh()->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'Please fix'));
    }

    public function test_replying_to_your_own_draft_replaces_the_text(): void
    {
        Queue::fake();
        [$product, $account] = $this->setUpCampaign(ContentSource::Manual);
        $post = app(DraftWriter::class)->createFromText($product, $account, 'First version');
        $post->update(['telegram_chat_id' => '555', 'telegram_message_id' => 60]);
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => ['message_id' => 78]])]);

        $this->reply(60, 'Second version');

        $this->assertSame('Second version', $post->fresh()->content);
        Queue::assertNotPushed(RegenerateDraft::class);
    }

    public function test_skip_drops_a_waiting_post(): void
    {
        [$product, $account, $campaign] = $this->setUpCampaign(ContentSource::Manual);
        $pending = app(DraftWriter::class)->createPending($product, $account, $campaign);
        $pending->update(['telegram_chat_id' => '555', 'telegram_message_id' => 50]);
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => true])]);

        app(UpdateHandler::class)->handle(['update_id' => 1, 'callback_query' => [
            'id' => 'cb', 'from' => ['id' => 9, 'username' => 'adam'], 'message' => ['message_id' => 50, 'chat' => ['id' => 555]], 'data' => "reject:{$pending->id}",
        ]]);

        $this->assertSame(PostStatus::Rejected, $pending->fresh()->status);
        // A skipped post was never written, so it is not shown as an empty draft.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText')
            && str_contains($r['text'], 'Skipped by @adam')
            && ! str_contains($r['text'], 'Link reply'));
    }

    public function test_the_dashboard_counts_posts_waiting_for_you(): void
    {
        $this->actingAs(User::factory()->create());
        [$product, $account, $campaign] = $this->setUpCampaign(ContentSource::Manual);
        app(DraftWriter::class)->createPending($product, $account, $campaign);
        app(DraftWriter::class)->createFromText(Product::factory()->for($product->affiliateAccount)->create(['affiliate_link' => 'https://s.shopee.com.my/two']), $account, 'Hi');

        Livewire::test(PerformanceOverview::class)->assertSeeInOrder(['Waiting for you', '2']);
    }

    public function test_ai_never_rewrites_text_you_wrote(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());
        [$product, $account] = $this->setUpCampaign(ContentSource::Manual);
        $post = app(DraftWriter::class)->createFromText($product, $account, 'Mine');
        $post->update(['telegram_chat_id' => '555', 'telegram_message_id' => 60]);
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => true])]);

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->assertActionHidden('regenerate')
            ->assertActionVisible('writePost');

        // An old Regenerate button, or a hand-made callback, is refused too.
        app(UpdateHandler::class)->handle(['update_id' => 3, 'callback_query' => [
            'id' => 'cb', 'from' => ['id' => 9, 'username' => 'adam'], 'message' => ['message_id' => 60, 'chat' => ['id' => 555]], 'data' => "regen:{$post->id}",
        ]]);
        Queue::assertNotPushed(RegenerateDraft::class);

        $this->expectException(ContentGenerationException::class);
        app(DraftWriter::class)->regenerate($post->fresh());
    }

    public function test_new_text_cannot_undo_an_approval_that_happened_meanwhile(): void
    {
        [$product, $account] = $this->setUpCampaign(ContentSource::Manual);
        $loaded = app(DraftWriter::class)->createFromText($product, $account, 'First version');
        // Approve lands after the reply handler loaded the post, but before it saves.
        Post::whereKey($loaded->id)->update(['status' => PostStatus::Approved]);

        try {
            app(DraftWriter::class)->fillFromText($loaded, 'Second version');
            $this->fail('Expected the late text to be refused.');
        } catch (ContentGenerationException) {
        }

        $this->assertSame(PostStatus::Approved, $loaded->fresh()->status);
        $this->assertSame('First version', $loaded->fresh()->content);
    }

    public function test_a_waiting_post_can_be_deleted_in_the_admin(): void
    {
        $this->actingAs(User::factory()->create());
        [$product, $account, $campaign] = $this->setUpCampaign(ContentSource::Manual);
        $pending = app(DraftWriter::class)->createPending($product, $account, $campaign);

        Livewire::test(EditPost::class, ['record' => $pending->getRouteKey()])
            ->callAction('delete');

        $this->assertModelMissing($pending);
    }

    public function test_run_now_says_which_accounts_get_a_prompt_instead_of_a_draft(): void
    {
        [$product, $manual, $campaign] = $this->setUpCampaign(ContentSource::Manual);
        $campaign->socialAccounts()->attach(SocialAccount::factory()->count(2)->create());

        $this->assertSame(
            'For "'.$product->name.'": AI drafts for 2 accounts arrive in Telegram for approval, and 1 account gets a "Your turn" prompt for you to write.',
            CampaignsTable::runMessage($campaign, $product),
        );
    }

    public function test_a_prompt_too_long_for_telegram_points_to_the_admin(): void
    {
        [$product, $account, $campaign] = $this->setUpCampaign(ContentSource::Manual);
        // Emoji count double in Telegram's limit.
        $account->update(['persona' => [...$account->persona, 'sample_posts' => [str_repeat('🔥', 1400)]]]);
        $pending = app(DraftWriter::class)->createPending($product, $account->fresh(), $campaign);

        $text = DraftMessage::text($pending->fresh());

        $this->assertStringContainsString('open this post in the admin', $text);
        $this->assertStringNotContainsString('<pre>', $text);
    }

    public function test_the_prompt_in_telegram_has_plain_apostrophes(): void
    {
        [$product, $account, $campaign] = $this->setUpCampaign(ContentSource::Manual);
        $pending = app(DraftWriter::class)->createPending($product, $account, $campaign);

        $text = DraftMessage::text($pending->fresh());

        // Telegram's HTML mode does not know &apos;, so it would show it literally.
        $this->assertStringNotContainsString('&apos;', $text);
        $this->assertStringContainsString("account's own voice", $text);
    }

    public function test_a_message_that_is_not_a_reply_gets_a_hint(): void
    {
        [$product, $account, $campaign] = $this->setUpCampaign(ContentSource::Manual);
        $pending = app(DraftWriter::class)->createPending($product, $account, $campaign);
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        app(UpdateHandler::class)->handle(['update_id' => 4, 'message' => [
            'message_id' => 98, 'from' => ['id' => 9, 'username' => 'adam'], 'chat' => ['id' => 555], 'text' => self::ANSWER,
        ]]);

        $this->assertSame(PostStatus::Pending, $pending->fresh()->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Reply to')
            && str_contains($r['text'], "#{$pending->id}"));
    }

    public function test_messages_from_other_chats_get_no_hint(): void
    {
        Http::fake();

        app(UpdateHandler::class)->handle(['update_id' => 5, 'message' => [
            'message_id' => 1, 'from' => ['id' => 7], 'chat' => ['id' => 999], 'text' => 'hello',
        ]]);

        Http::assertNothingSent();
    }

    private function reply(int $toMessageId, string $text): void
    {
        app(UpdateHandler::class)->handle(['update_id' => 2, 'message' => [
            'message_id' => 99, 'from' => ['id' => 9, 'username' => 'adam'], 'chat' => ['id' => 555],
            'text' => $text, 'reply_to_message' => ['message_id' => $toMessageId],
        ]]);
    }

    /**
     * @return array{Product, SocialAccount, Campaign}
     */
    private function setUpCampaign(ContentSource $source = ContentSource::Ai): array
    {
        $affiliate = AffiliateAccount::factory()->create();
        $account = SocialAccount::factory()->create([
            'content_source' => $source,
            'persona' => ['language' => 'Malay (rojak)', 'tone' => 'Santai', 'audience' => 'Students', 'disclosure' => 'Link affiliate ya.', 'sample_posts' => []],
        ]);
        $campaign = Campaign::factory()->for($affiliate)->create();
        $campaign->socialAccounts()->attach($account);
        $product = Product::factory()->for($affiliate)->create(['affiliate_link' => 'https://s.shopee.com.my/pb']);

        return [$product, $account, $campaign];
    }
}
