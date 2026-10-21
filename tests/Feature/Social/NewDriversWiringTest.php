<?php

namespace Tests\Feature\Social;

use App\Content\Data\ContentRequest;
use App\Content\PromptBuilder;
use App\Enums\SocialPlatform;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\SocialAccounts\Pages\CreateSocialAccount;
use App\Jobs\FetchPostInsights;
use App\Models\Post;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class NewDriversWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_insights_skip_platforms_without_metrics(): void
    {
        Queue::fake();
        $facebook = $this->published(SocialPlatform::FacebookPage);
        $this->published(SocialPlatform::TelegramChannel);

        $this->artisan('insights:fetch')->assertSuccessful();

        Queue::assertPushed(FetchPostInsights::class, 1);
        Queue::assertPushed(FetchPostInsights::class, fn (FetchPostInsights $job) => $job->post->is($facebook));
    }

    public function test_refresh_insights_is_hidden_for_telegram_channels(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(EditPost::class, ['record' => $this->published(SocialPlatform::TelegramChannel)->getRouteKey()])
            ->assertActionHidden('refreshInsights');
        Livewire::test(EditPost::class, ['record' => $this->published(SocialPlatform::FacebookPage)->getRouteKey()])
            ->assertActionVisible('refreshInsights');
    }

    public function test_long_form_platforms_still_get_a_readable_length_target(): void
    {
        $prompt = fn (int $max) => app(PromptBuilder::class)->build(
            new ContentRequest(Product::factory()->create(), SocialAccount::factory()->create()),
            maxLength: $max,
        )->system;

        $this->assertStringContainsString('under 450 characters', $prompt(500));
        $this->assertStringContainsString('under 600 characters', $prompt(5000));
    }

    public function test_the_account_form_explains_what_each_platform_needs(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateSocialAccount::class)
            ->fillForm(['platform' => SocialPlatform::FacebookPage])
            ->assertSee('Page ID')
            ->assertSee('Page access token')
            ->fillForm(['platform' => SocialPlatform::TelegramChannel])
            ->assertSee('@channelname')
            ->assertSee('bot token');
    }

    private function published(SocialPlatform $platform): Post
    {
        $account = SocialAccount::factory()->create(['platform' => $platform, 'external_id' => '1', 'access_token' => 't']);

        return Post::factory()->published()->create([
            'social_account_id' => $account->id,
            'external_post_id' => '99',
            'published_at' => now()->subDay(),
            'link_reply_content' => 'x',
        ]);
    }
}
