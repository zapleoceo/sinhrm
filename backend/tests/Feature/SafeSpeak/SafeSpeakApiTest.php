<?php

declare(strict_types=1);

namespace Tests\Feature\SafeSpeak;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\SafeSpeak\Models\SafeSpeakReport;
use App\Modules\SafeSpeak\Services\SafeSpeakService;
use App\Modules\SafeSpeak\Support\AccessCode;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\NavBadgeAssertions;
use Tests\TestCase;

/** Safe Speak: anonymity (nothing identifying stored), hashed codes, brute-force limits, handler gate. Synthetic data. */
final class SafeSpeakApiTest extends TestCase
{
    use NavBadgeAssertions, RefreshDatabase;

    private const string IP = '203.0.113.77';

    private const string UA = 'SyntheticBrowser/1.0 (synthetic-ua-marker)';

    /**
     * The limiter was built with the in-memory test store at boot; rebuild it on the database store.
     *
     * @param  array<string, mixed>  $extra
     */
    private function useDatabaseCache(array $extra = []): void
    {
        config(['cache.default' => 'database'] + $extra);
        $this->app->forgetInstance(RateLimiter::class);
    }

    private function handler(): User
    {
        $user = User::factory()->withRole(UserRole::Admin)->create();
        $user->forceFill(['safe_speak_handler' => true])->save();

        return $user;
    }

    /** @return array{code: string, report: array<string, mixed>} */
    private function submit(string $subject = 'Unsafe ladder in the warehouse'): array
    {
        $data = $this->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->withHeaders(['User-Agent' => self::UA])
            ->postJson('/api/safe-speak/public/reports', ['category' => 'safety', 'subject' => $subject, 'body' => 'It wobbles.'])
            ->assertCreated()->json('data');
        $this->assertIsArray($data);

        return $data;
    }

    public function test_report_stores_no_identity_ip_or_user_agent(): void
    {
        // Real stores (not the in-memory test ones), to prove what would land in the database.
        $this->useDatabaseCache(['session.driver' => 'database']);
        $user = User::factory()->create(['name' => 'Synthetic Reporter', 'email' => 'reporter@example.test']);
        // Even a logged-in user submitting from the app leaves nothing that points back to them.
        $data = $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => self::IP])->withHeaders(['User-Agent' => self::UA])
            ->postJson('/api/safe-speak/public/reports', ['category' => 'harassment', 'subject' => 'Something happened', 'body' => 'Details'])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{4}(-[0-9A-Z]{4}){3}$/', (string) $data['code']);
        $this->assertArrayNotHasKey('id', $data['report']);

        // Schema: no user / employee / ip / agent columns, no time of day.
        foreach (['safe_speak_reports', 'safe_speak_messages'] as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                $this->assertDoesNotMatchRegularExpression('/user|employee|ip|agent|created_at|updated_at|session/', $column, "$table.$column");
            }
        }
        // Data: no trace of the IP, the user agent, the user's id/name/e-mail anywhere in the rows or the cache keys.
        $dump = json_encode([
            DB::table('safe_speak_reports')->get(),
            DB::table('safe_speak_messages')->get(),
            DB::table('cache')->pluck('key'),
        ]);
        foreach ([self::IP, 'synthetic-ua-marker', 'reporter@example.test', 'Synthetic Reporter'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $dump);
        }
        $this->assertGreaterThan(0, DB::table('cache')->count(), 'the submit limiter wrote its (hashed) bucket');
        $this->assertNull(DB::table('safe_speak_messages')->value('handler_id'));
        // The code itself is not stored — only its HMAC.
        $this->assertStringNotContainsString(AccessCode::normalize((string) $data['code']), (string) $dump);
        $this->assertSame(AccessCode::hash((string) $data['code'], (string) config('app.key')), SafeSpeakReport::query()->sole()->access_code_hash);
        // The anonymous routes run without a session: nothing written to the sessions table.
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_follow_up_and_reply_by_code(): void
    {
        $data = $this->submit();
        $code = strtolower(str_replace('-', ' ', (string) $data['code']));

        $this->postJson('/api/safe-speak/public/follow-up', ['code' => $code])->assertOk()
            ->assertJsonPath('data.subject', 'Unsafe ladder in the warehouse')
            ->assertJsonPath('data.messages.0.author', 'reporter');
        $this->postJson('/api/safe-speak/public/reply', ['code' => $data['code'], 'body' => 'Photo is in the kitchen.'])->assertCreated()
            ->assertJsonCount(2, 'data.messages');

        $handler = $this->handler();
        $this->assertBadgeMatchesList($handler, 'safe_speak', '/api/safe-speak/reports?status=new', 1);
        $this->assertArrayNotHasKey('safe_speak', $this->badgesOf(User::factory()->withRole(UserRole::Admin)->create()));
        $id = $this->actingAs($handler)->getJson('/api/safe-speak/reports')->assertOk()->assertJsonPath('data.0.messages_count', 2)->json('data.0.id');
        $this->actingAs($handler)->postJson("/api/safe-speak/reports/$id/messages", ['body' => 'We are checking.'])->assertCreated()
            ->assertJsonPath('data.status', 'in_review');
        $this->assertBadgeMatchesList($handler, 'safe_speak', '/api/safe-speak/reports?status=new', 0);
        $reporterView = $this->postJson('/api/safe-speak/public/follow-up', ['code' => $data['code']])->assertOk()->json('data');
        $this->assertSame('handler', $reporterView['messages'][2]['author']);
        $this->assertStringNotContainsString((string) $handler->name, (string) json_encode($reporterView), 'the reporter does not learn who handles it');

        $this->actingAs($handler)->patchJson("/api/safe-speak/reports/$id", ['status' => 'closed'])->assertOk();
        $this->postJson('/api/safe-speak/public/reply', ['code' => $data['code'], 'body' => 'more'])->assertStatus(409);
        // The code travels only in the body.
        $this->getJson('/api/safe-speak/public/follow-up?code='.$data['code'])->assertStatus(405);
    }

    public function test_wrong_codes_are_rate_limited_per_hashed_client(): void
    {
        $this->useDatabaseCache();
        $data = $this->submit();
        for ($i = 0; $i < SafeSpeakService::FAILED_CODES; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => self::IP])->postJson('/api/safe-speak/public/follow-up', ['code' => 'AAAA-BBBB-CCCC-'.sprintf('%04d', $i)])
                ->assertNotFound()->assertJsonPath('code', 'invalid_code');
        }
        // Even the right code is refused while the bucket is blocked.
        $this->withServerVariables(['REMOTE_ADDR' => self::IP])->postJson('/api/safe-speak/public/follow-up', ['code' => $data['code']])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts')->assertHeader('Retry-After');
        // Another client is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->postJson('/api/safe-speak/public/follow-up', ['code' => $data['code']])->assertOk();
        $this->assertGreaterThan(0, DB::table('cache')->count(), 'the limiter really writes to the database cache');
        $this->assertStringNotContainsString(self::IP, (string) json_encode(DB::table('cache')->pluck('key')));

        // Submissions are limited too.
        for ($i = 1; $i < SafeSpeakService::SUBMITS_PER_HOUR; $i++) {
            $this->submit('Report '.$i);
        }
        $this->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->postJson('/api/safe-speak/public/reports', ['category' => 'other', 'subject' => 'One too many', 'body' => 'x'])->assertStatus(429);
    }

    public function test_handler_gate_needs_admin_and_explicit_flag(): void
    {
        $this->getJson('/api/safe-speak/reports')->assertUnauthorized();
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $this->actingAs($admin)->getJson('/api/safe-speak/reports')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/safe-speak/me')->assertOk()->assertJsonPath('data.handler', false);

        $recruiter = User::factory()->withRole(UserRole::Recruiter)->create();
        $recruiter->forceFill(['safe_speak_handler' => true])->save();
        $this->actingAs($recruiter)->getJson('/api/safe-speak/reports')->assertForbidden();

        $this->actingAs($this->handler())->getJson('/api/safe-speak/reports')->assertOk();
        $this->postJson('/api/safe-speak/public/reports', ['category' => 'nope', 'subject' => 'x', 'body' => 'y'])->assertUnprocessable();
        // Validation errors are JSON even without an Accept header (no session to redirect with).
        $this->post('/api/safe-speak/public/reports', ['category' => 'nope'])->assertUnprocessable()->assertJsonStructure(['errors']);
    }

    public function test_superadmin_toggles_the_handler_flag_admins_only(): void
    {
        $super = User::factory()->withRole(UserRole::Superadmin)->create();
        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $recruiter = User::factory()->withRole(UserRole::Recruiter)->create();

        $this->actingAs($super)->patchJson("/api/users/{$admin->id}", ['safe_speak_handler' => true])->assertOk()->assertJsonPath('data.safe_speak_handler', true);
        $this->actingAs($super)->patchJson("/api/users/{$recruiter->id}", ['safe_speak_handler' => true])->assertUnprocessable()->assertJsonPath('code', 'handler_requires_admin');
        // A superadmin may flag themself (it is not a role/status change).
        $this->actingAs($super)->patchJson("/api/users/{$super->id}", ['safe_speak_handler' => true])->assertOk();
        // Demotion drops the flag.
        $this->actingAs($super)->patchJson("/api/users/{$admin->id}", ['role' => 'recruiter'])->assertOk()->assertJsonPath('data.safe_speak_handler', false);
        $this->actingAs($admin)->patchJson("/api/users/{$super->id}", ['safe_speak_handler' => false])->assertForbidden();
    }
}
