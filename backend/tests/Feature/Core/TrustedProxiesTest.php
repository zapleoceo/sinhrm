<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Modules\SafeSpeak\Services\SafeSpeakService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Client IP behind the Vercel PHP runtime: X-Forwarded-For is believed only from config('trustedproxy.proxies')
 * (loopback launcher on Vercel). Synthetic documentation addresses (RFC 5737) only.
 */
final class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    private const string LAUNCHER = '127.0.0.1';

    private const string CLIENT_A = '203.0.113.10';

    private const string CLIENT_B = '198.51.100.20';

    protected function setUp(): void
    {
        parent::setUp();
        // Test-only echo route: what $request->ip() resolves to after the global TrustProxies middleware.
        Route::get('/__test/client-ip', fn (Request $request): array => ['ip' => $request->ip()]);
    }

    private function trustLauncher(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1,::1']);
    }

    /** @return TestResponse<Response> */
    private function ipFor(string $remoteAddr, ?string $forwardedFor): TestResponse
    {
        $headers = $forwardedFor === null ? [] : ['X-Forwarded-For' => $forwardedFor];
        $this->flushHeaders();

        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddr])->withHeaders($headers)->getJson('/__test/client-ip');
    }

    public function test_forwarded_for_from_the_trusted_launcher_is_the_client_ip(): void
    {
        $this->trustLauncher();

        $this->ipFor(self::LAUNCHER, self::CLIENT_A)->assertOk()->assertJsonPath('ip', self::CLIENT_A);
        $this->ipFor('::1', self::CLIENT_B)->assertOk()->assertJsonPath('ip', self::CLIENT_B);
        // No header from the launcher → the launcher address, as before.
        $this->ipFor(self::LAUNCHER, null)->assertOk()->assertJsonPath('ip', self::LAUNCHER);
    }

    public function test_a_value_prepended_by_the_client_is_not_taken(): void
    {
        $this->trustLauncher();

        // If a chain ever arrives, the address appended by the proxy (rightmost untrusted) wins, not the client's guess.
        $this->ipFor(self::LAUNCHER, '192.0.2.99, '.self::CLIENT_A)->assertOk()->assertJsonPath('ip', self::CLIENT_A);
    }

    public function test_spoofing_has_no_effect_when_trust_is_off(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->ipFor(self::LAUNCHER, self::CLIENT_A)->assertOk()->assertJsonPath('ip', self::LAUNCHER);
    }

    public function test_forwarded_for_from_an_untrusted_peer_is_ignored(): void
    {
        $this->trustLauncher();

        // A direct (non-loopback) peer cannot choose its IP with the header.
        $this->ipFor(self::CLIENT_B, self::CLIENT_A)->assertOk()->assertJsonPath('ip', self::CLIENT_B);
    }

    public function test_only_forwarded_for_is_trusted_not_host_or_proto(): void
    {
        $this->trustLauncher();

        $this->withServerVariables(['REMOTE_ADDR' => self::LAUNCHER])
            ->withHeaders(['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https'])
            ->get('/__test/client-ip');

        $request = $this->app->make('request');
        $this->assertNotSame('evil.example', $request->getHost());
        $this->assertFalse($request->isSecure());
    }

    public function test_limiter_counts_different_clients_behind_the_launcher_separately(): void
    {
        $this->trustLauncher();

        for ($i = 0; $i < SafeSpeakService::SUBMITS_PER_HOUR; $i++) {
            $this->submitFrom(self::CLIENT_A)->assertCreated();
        }
        $this->submitFrom(self::CLIENT_A)->assertStatus(429);
        // Same launcher REMOTE_ADDR, another client: its own bucket.
        $this->submitFrom(self::CLIENT_B)->assertCreated();
    }

    public function test_without_trust_all_clients_behind_the_launcher_share_one_bucket(): void
    {
        config(['trustedproxy.proxies' => null]);

        for ($i = 0; $i < SafeSpeakService::SUBMITS_PER_HOUR; $i++) {
            $this->submitFrom(self::CLIENT_A)->assertCreated();
        }
        // The old behaviour on Vercel: everybody is 127.0.0.1, so a stranger is blocked too.
        $this->submitFrom(self::CLIENT_B)->assertStatus(429);
    }

    /** @return TestResponse<Response> */
    private function submitFrom(string $client): TestResponse
    {
        $this->flushHeaders();

        return $this->withServerVariables(['REMOTE_ADDR' => self::LAUNCHER])
            ->withHeaders(['X-Forwarded-For' => $client])
            ->postJson('/api/safe-speak/public/reports', ['category' => 'other', 'subject' => 'Synthetic', 'body' => 'x']);
    }
}
