<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class HttpsBehindProxyTest extends TestCase
{
    protected function tearDown(): void
    {
        URL::forceScheme(null);

        parent::tearDown();
    }

    public function test_links_use_https_when_the_app_url_does(): void
    {
        // Cloudflare and Traefik terminate TLS, so the request itself arrives over plain http.
        config(['app.url' => 'https://affiliate.example.com']);
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', url('/admin/login'));
        $this->assertStringStartsWith('https://', route('telegram.webhook'));
    }

    public function test_local_http_urls_are_left_alone(): void
    {
        config(['app.url' => 'http://social-affiliate.test']);
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('http://', url('/admin/login'));
    }
}
