<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Production runs behind Caddy (docker-compose.prod.yml), which terminates TLS and
 * reaches the app from a private Docker address.
 */
class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_proxy-probe', fn (Request $request) => [
            'ip' => $request->ip(),
            'secure' => $request->secure(),
        ]);
    }

    public function test_the_proxy_on_the_docker_network_gives_the_client_ip_and_https(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/_proxy-probe')
            ->assertExactJson(['ip' => '203.0.113.7', 'secure' => true]);
    }

    public function test_a_client_on_the_internet_cannot_fake_its_ip_or_scheme(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/_proxy-probe')
            ->assertExactJson(['ip' => '198.51.100.20', 'secure' => false]);
    }
}
