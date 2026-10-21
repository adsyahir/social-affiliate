<?php

namespace App\Providers;

use Anthropic\Client;
use App\Content\Claude\ClaudeContentGenerator;
use App\Content\Contracts\ContentGenerator;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The SDK leaves timeouts to its HTTP client, so the transport carries them. Tests swap it out.
        $this->app->bind('anthropic.http', fn (): GuzzleClient => new GuzzleClient([
            'timeout' => (float) config('services.anthropic.timeout'),
            'connect_timeout' => 10,
        ]));

        $this->app->singleton(Client::class, fn (Application $app): Client => new Client(
            apiKey: (string) config('services.anthropic.api_key'),
            baseUrl: (string) config('services.anthropic.base_url'),
            requestOptions: [
                'transporter' => $app->make('anthropic.http'),
                'maxRetries' => (int) config('services.anthropic.max_retries'),
            ],
        ));

        $this->app->bind(ContentGenerator::class, ClaudeContentGenerator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
