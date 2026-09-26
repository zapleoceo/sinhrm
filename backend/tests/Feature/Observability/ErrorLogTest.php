<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Integrations\Support\SecretScrubber;
use App\Modules\Observability\Models\ErrorEvent;
use App\Modules\Observability\Services\ErrorLogPruneJob;
use App\Modules\Observability\Services\ErrorRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** In-app error log: the exception reporter, grouping, scrubbing, retention, the client endpoint and the admin API. */
final class ErrorLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        Route::middleware('api')->get('api/_test/boom', static function (): never {
            throw new RuntimeException('token bot123456:ABCdef_ghi for ivan.petrenko@example.com, phone +380 67 123 45 67');
        })->name('test.boom');
    }

    public function test_unhandled_exception_is_recorded_scrubbed_with_route_and_user_only(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/_test/boom')->assertStatus(500);

        $event = ErrorEvent::query()->sole();
        $this->assertSame('server', $event->source);
        $this->assertSame(RuntimeException::class, $event->exception_class);
        $this->assertSame('test.boom', $event->route);
        $this->assertSame($user->id, $event->last_user_id);
        $this->assertSame('tests/Feature/Observability/ErrorLogTest.php', $event->file);
        $this->assertSame(1, $event->count);
        $this->assertStringNotContainsString('ABCdef', $event->message);
        $this->assertStringNotContainsString('example.com', $event->message);
        $this->assertStringNotContainsString('123 45 67', $event->message);
        $this->assertStringContainsString(SecretScrubber::REDACTED, $event->message);
        $this->assertStringContainsString('[email]', $event->message);
    }

    public function test_known_secret_from_the_vault_is_scrubbed(): void
    {
        app(SecretScrubber::class)->remember('s3cr3t-api-key');
        app(ErrorRecorder::class)->recordException(new RuntimeException('call failed with key s3cr3t-api-key'));

        $this->assertSame('call failed with key '.SecretScrubber::REDACTED, ErrorEvent::query()->sole()->message);
    }

    public function test_same_fingerprint_is_grouped_and_a_resolved_group_reopens(): void
    {
        $this->getJson('/api/_test/boom')->assertStatus(500);
        ErrorEvent::query()->update(['resolved_at' => Carbon::now()]);
        Carbon::setTestNow('2026-10-21 08:00:00');
        $this->getJson('/api/_test/boom')->assertStatus(500);

        $event = ErrorEvent::query()->sole();
        $this->assertSame(2, $event->count);
        $this->assertNull($event->resolved_at);
        $this->assertSame('2026-10-20 12:00:00', $event->first_seen_at->toDateTimeString());
        $this->assertSame('2026-10-21 08:00:00', $event->last_seen_at->toDateTimeString());
    }

    public function test_4xx_are_not_recorded(): void
    {
        $this->getJson('/api/errors')->assertUnauthorized();
        $this->getJson('/api/nope-missing')->assertNotFound();

        $this->assertSame(0, ErrorEvent::query()->count());
    }

    public function test_recorder_never_throws_when_the_table_is_gone(): void
    {
        Schema::drop('error_events');

        app(ErrorRecorder::class)->recordException(new RuntimeException('x'));
        $this->getJson('/api/_test/boom')->assertStatus(500);
        $this->addToAssertionCount(1);
    }

    public function test_retention_deletes_groups_not_seen_for_30_days(): void
    {
        $recorder = app(ErrorRecorder::class);
        Carbon::setTestNow('2026-09-01 00:00:00');
        $recorder->recordClient('TypeError', 'old', 'main.js:1:1', '/a', null);
        Carbon::setTestNow('2026-10-15 00:00:00');
        $recorder->recordClient('TypeError', 'fresh', 'main.js:2:1', '/b', null);

        $this->assertSame(['errors_pruned' => 1], (new ErrorLogPruneJob)->run(Carbon::parse('2026-10-20 12:00:00')));
        $this->assertSame(['fresh'], ErrorEvent::query()->pluck('message')->all());
    }

    public function test_client_endpoint_requires_auth_records_web_errors_and_is_rate_limited(): void
    {
        $payload = ['kind' => 'TypeError', 'message' => 'x is undefined, mail me at a@b.co', 'location' => 'chunk-ABC.js:10:5', 'route' => '/people/5?q=secret'];
        $this->postJson('/api/errors/client', $payload)->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/errors/client', ['kind' => 'x'])->assertUnprocessable();
        for ($i = 0; $i < 9; $i++) {
            $this->actingAs($user)->postJson('/api/errors/client', $payload)->assertNoContent();
        }
        $this->actingAs($user)->postJson('/api/errors/client', $payload)->assertStatus(429);

        $event = ErrorEvent::query()->sole();
        $this->assertSame('web', $event->source);
        $this->assertSame('/people/5', $event->route);
        $this->assertSame(9, $event->count);
        $this->assertSame('x is undefined, mail me at [email]', $event->message);
        $this->assertSame($user->id, $event->last_user_id);
    }

    public function test_log_is_superadmin_only_and_resolved_toggles(): void
    {
        app(ErrorRecorder::class)->recordClient('TypeError', 'boom', 'main.js:1:1', '/', null);
        $id = ErrorEvent::query()->sole()->id;

        $admin = User::factory()->withRole(UserRole::Admin)->create();
        $this->actingAs($admin)->getJson('/api/errors')->assertForbidden();
        $this->actingAs($admin)->patchJson("/api/errors/{$id}", ['resolved' => true])->assertForbidden();

        $super = User::factory()->withRole(UserRole::Superadmin)->create();
        $this->actingAs($super)->getJson('/api/errors')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.count', 1);
        $this->actingAs($super)->getJson("/api/errors/{$id}")->assertOk()->assertJsonPath('data.message', 'boom');
        $this->actingAs($super)->patchJson("/api/errors/{$id}", ['resolved' => true])->assertOk()->assertJsonPath('data.resolved', true);
        $this->actingAs($super)->getJson('/api/errors')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($super)->getJson('/api/errors?status=resolved')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($super)->patchJson("/api/errors/{$id}", ['resolved' => false])->assertOk()->assertJsonPath('data.resolved', false);
    }
}
