<?php

namespace Tests\Feature\Social;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Social\Data\PostContent;
use App\Social\Exceptions\AccessTokenException;
use App\Social\Exceptions\InvalidContentException;
use App\Social\Exceptions\SocialApiException;
use App\Social\Exceptions\SocialException;
use App\Social\SocialPublisherFactory;
use App\Social\Telegram\TelegramChannelPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramChannelPublisherTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.telegram.org/botchannel:token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.api_base_url' => 'https://api.telegram.org',
            // The approval bot is a different bot; the channel uses the account's own token.
            'services.telegram.bot_token' => 'approval:token',
        ]);
        Http::preventStrayRequests();
    }

    public function test_posts_are_sent_as_plain_text_with_the_account_bot(): void
    {
        Http::fake([self::API.'/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 31, 'chat' => ['id' => -1001234567890]]])]);

        $post = $this->publisher('@barangmurahmy')->publish(new PostContent('Murah <gila> & best'));

        $this->assertSame('31', $post->id);
        $this->assertSame('https://t.me/barangmurahmy/31', $post->permalink);
        Http::assertSent(fn (Request $r) => $r['chat_id'] === '@barangmurahmy'
            && $r['text'] === 'Murah <gila> & best'
            && ! isset($r->data()['parse_mode'])
            && $r['link_preview_options'] === ['is_disabled' => true]);
    }

    public function test_private_channel_links_use_the_c_form(): void
    {
        Http::fake([self::API.'/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 31]])]);

        $this->assertSame('https://t.me/c/1234567890/31', $this->publisher('-1001234567890')->publish(new PostContent('Hi'))->permalink);
    }

    public function test_the_link_reply_is_a_reply_with_its_preview_shown(): void
    {
        Http::fake([self::API.'/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 32]])]);

        $reply = $this->publisher('@barangmurahmy')->reply('31', new PostContent("Sini https://s.shopee.com.my/x\n\nLink affiliate ya."));

        $this->assertSame('32', $reply->id);
        Http::assertSent(fn (Request $r) => $r['reply_parameters'] === ['message_id' => 31]
            && $r['link_preview_options'] === ['is_disabled' => false]);
    }

    public function test_a_lost_connection_while_posting_is_not_retried(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        try {
            $this->publisher('@barangmurahmy')->publish(new PostContent('Hi'));
            $this->fail('Expected an exception.');
        } catch (SocialApiException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('may have been published', $e->getMessage());
        }
    }

    public function test_verify_reads_the_channel(): void
    {
        Http::fake([self::API.'/getChat' => Http::response(['ok' => true, 'result' => ['id' => -1001234567890, 'title' => 'Barang Murah', 'username' => 'barangmurahmy', 'type' => 'channel']])]);

        $profile = $this->publisher('@barangmurahmy')->verify();

        $this->assertSame('-1001234567890', $profile->id);
        $this->assertSame('barangmurahmy', $profile->username);
        $this->assertTrue($profile->isHandle);
        $this->assertTrue($profile->identifies('@barangmurahmy'));
        $this->assertTrue($profile->identifies('-1001234567890'));
        $this->assertFalse($profile->identifies('@someoneelse'));
    }

    public function test_errors_are_classified(): void
    {
        Http::fake([self::API.'/*' => Http::sequence()
            ->push(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)
            ->push(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 3]], 429)
            ->push(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot is not a member of the channel chat'], 403)]);
        $publisher = $this->publisher('@barangmurahmy');

        try {
            $publisher->verify();
            $this->fail('Expected an exception.');
        } catch (AccessTokenException) {
            // A revoked or wrong bot token needs a new one.
        }

        foreach ([true, false] as $retryable) {
            try {
                $publisher->verify();
                $this->fail('Expected an exception.');
            } catch (SocialApiException $e) {
                $this->assertSame($retryable, $e->retryable);
                $this->assertStringNotContainsString('channel:token', $e->getMessage());
            }
        }
    }

    public function test_channels_have_no_insights(): void
    {
        $this->assertFalse(SocialPublisherFactory::supportsInsights(SocialPlatform::TelegramChannel));

        $this->expectException(SocialException::class);
        $this->publisher('@barangmurahmy')->getInsights('31');
    }

    public function test_limits_and_setup_are_checked_before_sending(): void
    {
        Http::fake();

        try {
            $this->publisher('@barangmurahmy')->publish(new PostContent(str_repeat('a', 4097)));
            $this->fail('Expected a length error.');
        } catch (InvalidContentException) {
            // Expected.
        }

        try {
            $this->publisher(null)->publish(new PostContent('Hi'));
            $this->fail('Expected a missing channel error.');
        } catch (SocialException $e) {
            $this->assertStringContainsString('channel', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    private function publisher(?string $channel): TelegramChannelPublisher
    {
        $account = SocialAccount::factory()->create([
            'platform' => SocialPlatform::TelegramChannel,
            'external_id' => $channel,
            'access_token' => 'channel:token',
        ]);

        return app(SocialPublisherFactory::class)->make($account);
    }
}
