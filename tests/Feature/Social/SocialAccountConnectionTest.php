<?php

namespace Tests\Feature\Social;

use App\Enums\SocialPlatform;
use App\Filament\Resources\SocialAccounts\Pages\EditSocialAccount;
use App\Models\SocialAccount;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

class SocialAccountConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://graph.threads.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.threads.base_url' => self::BASE]);
        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_tokens_close_to_expiry_are_refreshed_and_stored(): void
    {
        $this->freezeSecond();
        $expiring = $this->threadsAccount(['access_token' => 'old-1', 'token_expires_at' => now()->addDays(3)]);
        $unknown = $this->threadsAccount(['access_token' => 'old-2', 'token_expires_at' => null]);
        $healthy = $this->threadsAccount(['access_token' => 'old-3', 'token_expires_at' => now()->addDays(40)]);
        $noToken = $this->threadsAccount(['access_token' => null, 'token_expires_at' => now()->addDay()]);
        $otherPlatform = SocialAccount::factory()->create(['platform' => SocialPlatform::X, 'access_token' => 'x', 'token_expires_at' => now()->addDay()]);
        Http::fake([self::BASE.'/refresh_access_token*' => fn (Request $r) => Http::response([
            'access_token' => 'new-'.$r['access_token'], 'token_type' => 'bearer', 'expires_in' => 5184000,
        ])]);

        $this->artisan('social:refresh-tokens')->assertSuccessful();

        $this->assertSame('new-old-1', $expiring->fresh()->access_token);
        $this->assertTrue($expiring->fresh()->token_expires_at->equalTo(now()->addSeconds(5184000)));
        $this->assertSame('new-old-2', $unknown->fresh()->access_token);
        $this->assertSame('old-3', $healthy->fresh()->access_token);
        $this->assertNull($noToken->fresh()->access_token);
        $this->assertSame('x', $otherPlatform->fresh()->access_token);
        Http::assertSentCount(2);
    }

    public function test_one_failed_refresh_does_not_stop_the_others(): void
    {
        $broken = $this->threadsAccount(['access_token' => 'revoked', 'token_expires_at' => now()->addDay()]);
        $fine = $this->threadsAccount(['access_token' => 'good', 'token_expires_at' => now()->addDay()]);
        $expired = $this->threadsAccount(['access_token' => 'dead', 'token_expires_at' => now()->subDay()]);
        Http::fake([self::BASE.'/refresh_access_token*' => fn (Request $r) => $r['access_token'] === 'revoked'
            ? Http::response(['error' => ['message' => 'Session has been invalidated', 'code' => 190]], 400)
            : Http::response(['access_token' => 'fresh', 'expires_in' => 5184000])]);

        $this->artisan('social:refresh-tokens')
            ->expectsOutputToContain('Session has been invalidated')
            ->expectsOutputToContain('expired')
            ->assertFailed();

        $this->assertSame('revoked', $broken->fresh()->access_token);
        $this->assertSame('fresh', $fine->fresh()->access_token);
        // An expired token cannot be refreshed, so it is reported without calling the API.
        Http::assertNotSent(fn (Request $r) => $r['access_token'] === 'dead');
        $this->assertSame('dead', $expired->fresh()->access_token);
    }

    public function test_token_refresh_runs_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->command, 'social:refresh-tokens'));

        $this->assertCount(1, $events);
        $this->assertSame('0 3 * * *', $events->first()->expression);
    }

    public function test_check_connection_shows_the_username_and_stores_the_account_id(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->threadsAccount(['external_id' => null]);
        Http::fake([self::BASE.'/v1.0/me*' => Http::response(['id' => '1789', 'username' => 'gadgets'])]);

        Livewire::test(EditSocialAccount::class, ['record' => $account->getRouteKey()])
            ->callAction('checkConnection')
            ->assertNotified('Connected as @gadgets');

        $this->assertSame('1789', $account->fresh()->external_id);
    }

    public function test_check_connection_corrects_a_wrong_threads_id(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->threadsAccount(['external_id' => '1547521060477411']);
        Http::fake([self::BASE.'/v1.0/me*' => Http::response(['id' => '1789', 'username' => 'gadgets'])]);

        Livewire::test(EditSocialAccount::class, ['record' => $account->getRouteKey()])
            ->callAction('checkConnection')
            ->assertNotified('Connected as @gadgets')
            ->assertSchemaStateSet(['external_id' => '1789']);

        $this->assertSame('1789', $account->fresh()->external_id);
    }

    public function test_check_connection_accepts_a_channel_given_by_its_name(): void
    {
        $this->actingAs(User::factory()->create());
        config(['services.telegram.api_base_url' => 'https://api.telegram.org']);
        $account = SocialAccount::factory()->create(['platform' => SocialPlatform::TelegramChannel, 'external_id' => '@barangmurahmy', 'access_token' => 'chan:token']);
        Http::fake(['https://api.telegram.org/botchan:token/getChat' => Http::response(['ok' => true, 'result' => ['id' => -1001999, 'username' => 'barangmurahmy', 'title' => 'Barang Murah']])]);

        Livewire::test(EditSocialAccount::class, ['record' => $account->getRouteKey()])
            ->callAction('checkConnection')
            ->assertNotified(Notification::make()->title('Connected as @barangmurahmy')->success());
    }

    public function test_page_names_are_shown_without_an_at_sign(): void
    {
        $this->actingAs(User::factory()->create());
        config(['services.facebook' => ['base_url' => 'https://graph.facebook.com', 'version' => 'v25.0']]);
        $account = SocialAccount::factory()->create(['platform' => SocialPlatform::FacebookPage, 'external_id' => '4242', 'access_token' => 'page-token']);
        Http::fake(['https://graph.facebook.com/v25.0/me*' => Http::response(['id' => '4242', 'name' => 'Gadget Picks'])]);

        Livewire::test(EditSocialAccount::class, ['record' => $account->getRouteKey()])
            ->callAction('checkConnection')
            ->assertNotified('Connected as Gadget Picks');
    }

    public function test_check_connection_reports_a_rejected_token(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->threadsAccount();
        Http::fake([self::BASE.'/*' => Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]], 400)]);

        Livewire::test(EditSocialAccount::class, ['record' => $account->getRouteKey()])
            ->callAction('checkConnection')
            ->assertNotified('Connection failed');
    }

    public function test_refresh_token_action_stores_the_new_token(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->threadsAccount(['access_token' => 'old']);
        Http::fake([self::BASE.'/refresh_access_token*' => Http::response(['access_token' => 'new', 'expires_in' => 5184000])]);

        Livewire::test(EditSocialAccount::class, ['record' => $account->getRouteKey()])
            ->callAction('refreshToken')
            ->assertNotified('Token refreshed');

        $this->assertSame('new', $account->fresh()->access_token);
        $this->assertNotNull($account->fresh()->token_expires_at);
    }

    public function test_test_post_publishes_and_replies(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->threadsAccount();
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/threads') => Http::response(['id' => isset($request['reply_to_id']) ? 'c-reply' : 'c-main']),
                str_ends_with($path, '/threads_publish') => Http::response(['id' => $request['creation_id'] === 'c-main' ? 'm-main' : 'm-reply']),
                default => Http::response(['status' => 'FINISHED', 'permalink' => 'https://www.threads.com/@gadgets/post/xyz']),
            };
        });

        Livewire::test(EditSocialAccount::class, ['record' => $account->getRouteKey()])
            ->callAction('sendTestPost', ['text' => 'Testing my setup', 'reply_text' => 'And a reply'])
            ->assertHasNoActionErrors()
            ->assertNotified('Test post published');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/threads') && $r['text'] === 'Testing my setup' && ! isset($r['reply_to_id']));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/threads') && $r['text'] === 'And a reply' && $r['reply_to_id'] === 'm-main');
    }

    public function test_values_set_by_the_actions_survive_a_later_save(): void
    {
        $this->freezeSecond();
        $this->actingAs(User::factory()->create());
        $account = $this->threadsAccount(['external_id' => null, 'token_expires_at' => null]);
        Http::fake([
            self::BASE.'/v1.0/me*' => Http::response(['id' => '1789', 'username' => 'gadgets']),
            self::BASE.'/refresh_access_token*' => Http::response(['access_token' => 'new', 'expires_in' => 5184000]),
        ]);

        Livewire::test(EditSocialAccount::class, ['record' => $account->getRouteKey()])
            ->callAction('checkConnection')
            ->callAction('refreshToken')
            ->assertSchemaStateSet(['external_id' => '1789'])
            ->call('save')
            ->assertHasNoFormErrors();

        $account->refresh();
        $this->assertSame('1789', $account->external_id);
        $this->assertTrue($account->token_expires_at->equalTo(now()->addSeconds(5184000)));
        $this->assertSame('new', $account->access_token);
    }

    public function test_saving_a_first_token_reloads_the_page_to_show_the_connection_menu(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->threadsAccount(['access_token' => null]);

        Livewire::test(EditSocialAccount::class, ['record' => $account->getRouteKey()])
            ->fillForm(['access_token' => 'token-abc'])
            ->call('save')
            ->assertRedirect(route('filament.admin.resources.social-accounts.edit', $account));
    }

    public function test_connection_actions_need_a_supported_platform_and_a_token(): void
    {
        $this->actingAs(User::factory()->create());
        $noToken = $this->threadsAccount(['access_token' => null]);
        $unsupported = SocialAccount::factory()->create(['platform' => SocialPlatform::X, 'access_token' => 'x']);

        foreach ([$noToken, $unsupported] as $account) {
            Livewire::test(EditSocialAccount::class, ['record' => $account->getRouteKey()])
                ->assertActionHidden('checkConnection')
                ->assertActionHidden('refreshToken')
                ->assertActionHidden('sendTestPost');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function threadsAccount(array $attributes = []): SocialAccount
    {
        return SocialAccount::factory()->create([
            'platform' => SocialPlatform::Threads,
            'external_id' => '1789',
            'access_token' => 'token-abc',
            ...$attributes,
        ]);
    }
}
