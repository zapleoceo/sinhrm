<?php

declare(strict_types=1);

namespace Tests\Feature\GoogleWorkspace;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\GoogleWorkspace\Contracts\GoogleTokenProvider;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use App\Modules\GoogleWorkspace\Services\GoogleSheetsClient;
use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Integrations\Models\IntegrationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\GoogleFixtures;
use Tests\TestCase;

/** Access token cache, refresh on demand, invalid_grant → reconnect_required. */
final class GoogleTokenTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configureGoogleClient();
        $this->captureLogs();
        $this->superadmin = User::factory()->withRole(UserRole::Superadmin)->create();
    }

    public function test_not_connected_service_throws_without_requests(): void
    {
        Http::fake();

        try {
            $this->app->make(GoogleTokenProvider::class)->accessToken(GoogleService::Gmail);
            $this->fail('expected exception');
        } catch (GoogleException $e) {
            $this->assertSame('google_gmail_not_connected', $e->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_valid_cached_token_is_used_without_refresh(): void
    {
        Http::fake();
        $this->connectGoogle(GoogleService::Gmail, $this->superadmin->id);

        $this->assertSame(self::ACCESS_TOKEN, $this->app->make(GoogleTokenProvider::class)->accessToken(GoogleService::Gmail));
        Http::assertNothingSent();
    }

    public function test_expired_token_is_refreshed_and_cached(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-new-access-7788', 'expires_in' => 3600])]);
        $this->connectGoogle(GoogleService::Gmail, $this->superadmin->id, expired: true);
        $tokens = $this->app->make(GoogleTokenProvider::class);

        $first = $tokens->accessToken(GoogleService::Gmail);
        $second = $tokens->accessToken(GoogleService::Gmail);

        $this->assertSame(['fake-new-access-7788', 'fake-new-access-7788'], [$first, $second]);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === self::REFRESH_TOKEN
            && $r['client_secret'] === self::CLIENT_SECRET);
        $this->assertSame('fake-new-access-7788', $this->app->make(SecretVault::class)->get('google_gmail', 'access_token'));
        $this->assertLogsDoNotContain('fake-new-access-7788', self::REFRESH_TOKEN, self::CLIENT_SECRET);
    }

    public function test_invalid_grant_marks_reconnect_required_and_warns_on_the_dashboard(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);
        $this->connectGoogle(GoogleService::Calendar, $this->superadmin->id, expired: true);
        $tokens = $this->app->make(GoogleTokenProvider::class);

        foreach ([1, 2] as $attempt) {
            try {
                $tokens->accessToken(GoogleService::Calendar);
                $this->fail('expected exception');
            } catch (GoogleException $e) {
                $this->assertSame('reconnect_required', $e->errorCode);
            }
        }
        // The second attempt does not hit Google again.
        Http::assertSentCount(1);

        $integration = Integration::query()->where('key', 'google_calendar')->firstOrFail();
        $this->assertSame('error', $integration->status->value);
        $this->assertSame('reconnect_required', $integration->last_error);
        $this->assertTrue(IntegrationLog::query()->where('message', 'reconnect_required')->exists());

        $this->actingAs($this->superadmin)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.warnings.0.code', 'google_reconnect_required')
            ->assertJsonPath('data.warnings.0.params.service', 'calendar');
        $recruiter = User::factory()->withRole(UserRole::Recruiter)->create();
        $this->actingAs($recruiter)->getJson('/api/dashboard')->assertJsonPath('data.warnings', []);
        $this->actingAs($this->superadmin)->getJson('/api/google/status')->assertJsonPath('data.1.error', 'reconnect_required');
    }

    public function test_api_401_refreshes_once_and_retries(): void
    {
        $this->connectGoogle(GoogleService::Sheets, $this->superadmin->id);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-second-access-9900', 'expires_in' => 3600]),
            'sheets.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['code' => 401]], 401)
                ->push(['values' => [['Name', 'Phone']]]),
        ]);

        $rows = $this->app->make(GoogleSheetsClient::class)->values('fake-sheet-id-000000000000', 'A1:Z1');

        $this->assertSame([['Name', 'Phone']], $rows);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'sheets.googleapis.com') && $r->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN));
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'sheets.googleapis.com') && $r->hasHeader('Authorization', 'Bearer fake-second-access-9900'));
    }
}
