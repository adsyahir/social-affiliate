<?php

namespace Tests\Feature\Telegram;

use App\Content\DraftWriter;
use App\Enums\AffiliateProgram;
use App\Enums\ContentSource;
use App\Enums\SocialPlatform;
use App\Jobs\GenerateDraft;
use App\Models\AffiliateAccount;
use App\Models\Campaign;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Telegram\UpdateHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Starting a post by sending the bot a product link, without opening the admin.
 */
class LinkIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const TG = 'https://api.telegram.org/bot123:tok';

    private const SHORT_LINK = 'https://s.shopee.com.my/9zylFKu0O7';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => '123:tok',
            'services.telegram.chat_id' => '555',
            'services.telegram.allowed_user_ids' => [],
            'services.telegram.api_base_url' => 'https://api.telegram.org',
            'services.anthropic.api_key' => '',
        ]);
        Http::preventStrayRequests();
        Http::fake([self::TG.'/*' => Http::response(['ok' => true, 'result' => ['message_id' => 70]])]);
        Queue::fake();
    }

    public function test_a_link_with_a_name_asks_who_writes_then_starts_your_post(): void
    {
        [$affiliate, $campaign] = $this->shopeeCampaign();
        $account = $this->account($campaign, 'adamdigitalp');

        $this->send(self::SHORT_LINK."\nTYESO Tumbler 1200ml");

        // Nothing is saved or started until you say who writes it.
        $this->assertDatabaseCount('products', 0);
        Queue::assertNotPushed(GenerateDraft::class);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'TYESO Tumbler 1200ml') && str_contains($r['text'], 'Who writes this one'));
        $this->assertSame(['✍️ I write', '🤖 AI writes'], array_keys($this->buttons()));

        $this->tap($this->buttons()['✍️ I write']);

        $product = Product::sole();
        $this->assertSame('TYESO Tumbler 1200ml', $product->name);
        $this->assertSame(self::SHORT_LINK, $product->affiliate_link);
        $this->assertSame($affiliate->id, $product->affiliate_account_id);
        Queue::assertPushed(GenerateDraft::class, fn (GenerateDraft $job) => $job->account->is($account)
            && $job->product->is($product) && $job->campaign->is($campaign) && $job->source === ContentSource::Manual);
        // The question loses its buttons, so a second tap cannot start a second post.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText')
            && str_contains($r['text'], 'You write it') && $r['reply_markup'] === ['inline_keyboard' => []]);
    }

    public function test_ai_writes_it_when_chosen_even_for_an_account_set_to_manual(): void
    {
        config(['services.anthropic.api_key' => 'sk-test']);
        [, $campaign] = $this->shopeeCampaign();
        $this->account($campaign, 'adamdigitalp');
        $this->send(self::SHORT_LINK."\nTYESO Tumbler");

        $this->tap($this->buttons()['🤖 AI writes']);

        Queue::assertPushed(GenerateDraft::class, fn (GenerateDraft $job) => $job->source === ContentSource::Ai);
    }

    public function test_ai_without_an_api_key_explains_and_starts_nothing(): void
    {
        [, $campaign] = $this->shopeeCampaign();
        $this->account($campaign, 'adamdigitalp');
        $this->send(self::SHORT_LINK."\nTYESO Tumbler");

        $this->tap($this->buttons()['🤖 AI writes']);

        $this->assertDatabaseCount('products', 0);
        Queue::assertNotPushed(GenerateDraft::class);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery') && str_contains($r['text'], 'API key'));
    }

    public function test_with_several_accounts_the_bot_asks_which_one_first(): void
    {
        [, $campaign] = $this->shopeeCampaign();
        $threads = $this->account($campaign, 'adamdigitalp');
        $this->account($campaign, 'Adam Test Deals', SocialPlatform::TelegramChannel);

        $this->send('TYESO Tumbler '.self::SHORT_LINK);

        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'Which account'));
        $this->assertSame(['Adam Test Deals', 'adamdigitalp'], array_keys($this->buttons()));

        $this->tap($this->buttons()['adamdigitalp']);

        // The same message now asks who writes it.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText')
            && str_contains($r['text'], 'adamdigitalp') && str_contains($r['text'], 'Who writes this one'));

        $this->tap($this->buttons()['✍️ I write']);

        Queue::assertPushed(GenerateDraft::class, fn (GenerateDraft $job) => $job->account->is($threads));
    }

    public function test_a_double_tap_starts_one_post(): void
    {
        [, $campaign] = $this->shopeeCampaign();
        $this->account($campaign, 'adamdigitalp');
        $this->send(self::SHORT_LINK."\nTYESO Tumbler");
        $iWrite = $this->buttons()['✍️ I write'];

        $this->tap($iWrite);
        $this->tap($iWrite);
        // The same link sent again before the first draft exists.
        $this->send(self::SHORT_LINK."\nTYESO Tumbler");
        $this->tap($this->buttons()['✍️ I write']);

        $this->assertDatabaseCount('products', 1);
        Queue::assertPushed(GenerateDraft::class, 1);
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'already being started'));
    }

    public function test_a_waiting_post_is_not_doubled(): void
    {
        [, $campaign] = $this->shopeeCampaign();
        $account = $this->account($campaign, 'adamdigitalp');
        $product = Product::factory()->for($campaign->affiliateAccount)->create(['affiliate_link' => self::SHORT_LINK]);
        app(DraftWriter::class)->createPending($product, $account, $campaign);

        $this->send(self::SHORT_LINK);
        $this->tap($this->buttons()['✍️ I write']);

        Queue::assertNotPushed(GenerateDraft::class);
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'already waiting'));
    }

    public function test_buttons_cannot_pair_a_link_with_an_account_outside_its_campaigns(): void
    {
        [, $campaign] = $this->shopeeCampaign();
        $this->account($campaign, 'adamdigitalp');
        $outsider = SocialAccount::factory()->create();
        $this->send(self::SHORT_LINK."\nTYESO Tumbler");
        [$action, $token] = explode(':', $this->buttons()['✍️ I write']);

        $this->tap("{$action}:{$token}:{$outsider->id}:manual");

        Queue::assertNotPushed(GenerateDraft::class);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_the_name_comes_from_a_full_product_link_and_tracking_parameters_are_dropped(): void
    {
        $affiliate = AffiliateAccount::factory()->create(['program' => AffiliateProgram::Amazon, 'tracking_id' => 'adam-20']);
        $campaign = Campaign::factory()->for($affiliate)->create();
        $this->account($campaign, 'adamdigitalp');

        $this->send('https://www.amazon.com/Anker-Portable-Charger-PowerCore-10000mAh/dp/B019GJLER8/ref=sr_1_1?th=1');
        $this->tap($this->buttons()['✍️ I write']);
        $this->send('https://www.amazon.com/Anker-Portable-Charger-PowerCore-10000mAh/dp/B019GJLER8?ref=other');

        $product = Product::sole();
        $this->assertSame('Anker Portable Charger PowerCore 10000mAh', $product->name);
        $this->assertSame('https://www.amazon.com/dp/B019GJLER8', $product->affiliate_link);
    }

    public function test_shopee_share_text_is_not_part_of_the_name(): void
    {
        [, $campaign] = $this->shopeeCampaign();
        $this->account($campaign, 'adamdigitalp');

        $this->send('Check out TYESO Tumbler 1200ml for RM39.90. Get it on Shopee now! '.self::SHORT_LINK);
        $this->tap($this->buttons()['✍️ I write']);

        $this->assertSame('TYESO Tumbler 1200ml', Product::sole()->name);
    }

    public function test_a_linked_word_is_read_from_telegrams_link_data(): void
    {
        [, $campaign] = $this->shopeeCampaign();
        $this->account($campaign, 'adamdigitalp');

        $this->send('TYESO Tumbler', entities: [['type' => 'text_link', 'offset' => 0, 'length' => 5, 'url' => self::SHORT_LINK]]);
        $this->tap($this->buttons()['✍️ I write']);

        $this->assertSame(self::SHORT_LINK, Product::sole()->affiliate_link);
    }

    public function test_a_short_link_without_a_name_asks_for_one_and_a_reply_with_it_works(): void
    {
        [, $campaign] = $this->shopeeCampaign();
        $this->account($campaign, 'adamdigitalp');

        $this->send(self::SHORT_LINK);
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'product name on the next line'));

        // Answering the question with a reply instead of a new message.
        $this->send(self::SHORT_LINK."\nTYESO Tumbler", replyTo: 70);

        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'Who writes this one'));
    }

    public function test_an_untracked_shopee_link_is_refused_with_the_reason(): void
    {
        [, $campaign] = $this->shopeeCampaign();
        $this->account($campaign, 'adamdigitalp');

        $this->send('https://shopee.com.my/TYESO-Vacuum-Insulated-Tumbler-i.1390730485.51569272536');

        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'no tracked Shopee link'));
        Http::assertNotSent(fn (Request $r) => str_contains((string) $r['text'], 'Who writes'));
    }

    public function test_the_affiliate_account_with_somewhere_to_post_is_used(): void
    {
        $empty = AffiliateAccount::factory()->create(['program' => AffiliateProgram::Shopee]);
        Campaign::factory()->for($empty)->count(2)->create();
        [$usable, $campaign] = $this->shopeeCampaign();
        $this->account($campaign, 'adamdigitalp');

        $this->send(self::SHORT_LINK."\nTYESO Tumbler");
        $this->tap($this->buttons()['✍️ I write']);

        $this->assertSame($usable->id, Product::sole()->affiliate_account_id);
    }

    public function test_links_need_an_affiliate_account_and_a_campaign(): void
    {
        $this->send(self::SHORT_LINK."\nTYESO Tumbler");
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'Add a Shopee affiliate account'));

        AffiliateAccount::factory()->create(['program' => AffiliateProgram::Shopee]);
        $this->send(self::SHORT_LINK."\nTYESO Tumbler");
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'campaign'));

        Queue::assertNotPushed(GenerateDraft::class);
    }

    public function test_other_sites_are_explained(): void
    {
        $this->send('https://www.lazada.com.my/products/thing-i123.html');

        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'Shopee and Amazon'));
    }

    public function test_links_from_other_chats_are_ignored(): void
    {
        [, $campaign] = $this->shopeeCampaign();
        $this->account($campaign, 'adamdigitalp');

        $this->send(self::SHORT_LINK."\nTYESO Tumbler", chatId: 999);

        Http::assertNothingSent();
    }

    /**
     * @return array{AffiliateAccount, Campaign}
     */
    private function shopeeCampaign(): array
    {
        $affiliate = AffiliateAccount::factory()->create(['program' => AffiliateProgram::Shopee]);

        return [$affiliate, Campaign::factory()->for($affiliate)->create()];
    }

    private function account(Campaign $campaign, string $name, SocialPlatform $platform = SocialPlatform::Threads): SocialAccount
    {
        $account = SocialAccount::factory()->create(['name' => $name, 'platform' => $platform, 'content_source' => ContentSource::Manual]);
        $campaign->socialAccounts()->attach($account);

        return $account;
    }

    /**
     * The buttons on the latest message the bot sent or edited, as label => callback data.
     *
     * @return array<string, string>
     */
    private function buttons(): array
    {
        $withButtons = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $r) => ! empty($r['reply_markup']['inline_keyboard']))
            ->last();

        return collect($withButtons['reply_markup']['inline_keyboard'] ?? [])
            ->flatten(1)
            ->mapWithKeys(fn (array $button) => [$button['text'] => $button['callback_data']])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $entities
     */
    private function send(string $text, int $chatId = 555, ?int $replyTo = null, array $entities = []): void
    {
        app(UpdateHandler::class)->handle(['update_id' => 1, 'message' => array_filter([
            'message_id' => 40, 'from' => ['id' => 9, 'username' => 'adam'], 'chat' => ['id' => $chatId], 'text' => $text,
            'entities' => $entities ?: null,
            'reply_to_message' => $replyTo === null ? null : ['message_id' => $replyTo],
        ], fn ($value) => $value !== null)]);
    }

    private function tap(string $data): void
    {
        app(UpdateHandler::class)->handle(['update_id' => 2, 'callback_query' => [
            'id' => 'cb', 'from' => ['id' => 9, 'username' => 'adam'], 'message' => ['message_id' => 70, 'chat' => ['id' => 555]], 'data' => $data,
        ]]);
    }
}
