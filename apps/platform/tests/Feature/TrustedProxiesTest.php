<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * X-Forwarded-* headers are honored only from the proxies in
 * TRUSTED_PROXIES (Traefik, Nginx, Cloudflare...), never by default.
 */
class TrustedProxiesTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    public function test_forwarded_headers_are_ignored_unless_the_proxy_is_trusted(): void
    {
        Route::get('/_proxy-probe', fn (Request $request) => $request->getScheme().'|'.$request->ip());
        $headers = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'];

        $this->get('/_proxy-probe', $headers)->assertSeeText('http|127.0.0.1');

        config(['starter.trusted_proxies' => '*']);
        (new AppServiceProvider($this->app))->boot();

        $this->get('/_proxy-probe', $headers)->assertSeeText('https|203.0.113.9');
    }
}
