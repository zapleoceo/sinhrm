<?php

declare(strict_types=1);

namespace Tests\Feature\SafeSpeak;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\SafeSpeak\Http\Controllers\PublicReportController;
use App\Modules\SafeSpeak\Models\SafeSpeakReport;
use App\Modules\SafeSpeak\Services\SafeSpeakService;
use App\Modules\SafeSpeak\Support\AccessCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Safe Speak — every route: handler gate per role, 401/403/404/409/422, status filter as a string, anonymity of the
 * handler towards the reporter and of the reporter towards the handler, HMAC of the access code bound to APP_KEY.
 */
final class SafeSpeakRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function handler(UserRole $role = UserRole::Admin): User
    {
        $user = User::factory()->withRole($role)->create();
        $user->forceFill(['safe_speak_handler' => true])->save();

        return $user;
    }

    /** @return array{code: string, id: int} */
    private function submit(string $category = 'fraud'): array
    {
        $code = (string) $this->postJson('/api/safe-speak/public/reports', ['category' => $category, 'subject' => '  Invoice padding  ', 'body' => 'Numbers do not add up.'])
            ->assertCreated()->assertJsonPath('data.report.subject', 'Invoice padding')->assertJsonPath('data.report.status', 'new')->json('data.code');

        return ['code' => $code, 'id' => SafeSpeakReport::query()->latest('id')->value('id')];
    }

    /** @return iterable<string, array{string, string}> */
    public static function handlerRoutes(): iterable
    {
        yield 'me' => ['GET', '/api/safe-speak/me'];
        yield 'index' => ['GET', '/api/safe-speak/reports'];
        yield 'show' => ['GET', '/api/safe-speak/reports/1'];
        yield 'update' => ['PATCH', '/api/safe-speak/reports/1'];
        yield 'reply' => ['POST', '/api/safe-speak/reports/1/messages'];
    }

    #[DataProvider('handlerRoutes')]
    public function test_handler_routes_need_login(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    /** @return iterable<string, array{UserRole, bool}> */
    public static function roles(): iterable
    {
        yield 'superadmin' => [UserRole::Superadmin, true];
        yield 'admin' => [UserRole::Admin, true];
        yield 'hr_manager' => [UserRole::HrManager, true];
        yield 'recruiter' => [UserRole::Recruiter, false];
        yield 'employee' => [UserRole::Employee, false];
        yield 'viewer' => [UserRole::Viewer, false];
    }

    #[DataProvider('roles')]
    public function test_flagged_user_handles_only_with_an_hr_staff_role(UserRole $role, bool $allowed): void
    {
        $report = $this->submit();
        $user = $this->handler($role);
        $this->actingAs($user)->getJson('/api/safe-speak/me')->assertOk()->assertJsonPath('data.handler', $allowed);
        $calls = [
            $this->actingAs($user)->getJson('/api/safe-speak/reports'),
            $this->actingAs($user)->getJson("/api/safe-speak/reports/{$report['id']}"),
            $this->actingAs($user)->patchJson("/api/safe-speak/reports/{$report['id']}", ['status' => 'in_review']),
            $this->actingAs($user)->postJson("/api/safe-speak/reports/{$report['id']}/messages", ['body' => 'Looking into it']),
        ];
        foreach ($calls as $response) {
            $allowed ? $response->assertSuccessful() : $response->assertForbidden();
        }
    }

    public function test_blocked_handler_is_refused(): void
    {
        $user = User::factory()->blocked()->withRole(UserRole::Admin)->create();
        $user->forceFill(['safe_speak_handler' => true])->save();
        $this->actingAs($user)->getJson('/api/safe-speak/reports')->assertForbidden();
    }

    public function test_unknown_report_is_404_for_every_handler_route(): void
    {
        $h = $this->handler();
        $this->actingAs($h)->getJson('/api/safe-speak/reports/999999')->assertNotFound();
        $this->actingAs($h)->patchJson('/api/safe-speak/reports/999999', ['status' => 'closed'])->assertNotFound();
        $this->actingAs($h)->postJson('/api/safe-speak/reports/999999/messages', ['body' => 'x'])->assertNotFound();
    }

    public function test_handler_validation_and_status_filter_as_string(): void
    {
        $a = $this->submit();
        $this->submit('ethics');
        $h = $this->handler();
        $this->actingAs($h)->patchJson("/api/safe-speak/reports/{$a['id']}", ['status' => 'closed'])->assertOk()->assertJsonPath('data.status', 'closed');

        $this->actingAs($h)->getJson('/api/safe-speak/reports?status=closed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a['id']);
        $this->actingAs($h)->getJson('/api/safe-speak/reports?status=new')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($h)->getJson('/api/safe-speak/reports?status=archived')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($h)->patchJson("/api/safe-speak/reports/{$a['id']}", [])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($h)->patchJson("/api/safe-speak/reports/{$a['id']}", ['status' => 'deleted'])->assertUnprocessable();
        $this->actingAs($h)->postJson("/api/safe-speak/reports/{$a['id']}/messages", [])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->actingAs($h)->postJson("/api/safe-speak/reports/{$a['id']}/messages", ['body' => str_repeat('b', 10001)])->assertUnprocessable();
        // Closed report: the handler cannot reply either.
        $this->actingAs($h)->postJson("/api/safe-speak/reports/{$a['id']}/messages", ['body' => 'late'])->assertStatus(409)->assertJsonPath('code', 'report_closed');
    }

    public function test_handler_sees_nothing_about_the_reporter_and_reporter_nothing_about_handler(): void
    {
        $r = $this->submit();
        $h = $this->handler();
        $h->forceFill(['name' => 'Synthetic Handler Name', 'email' => 'handler@example.test'])->save();
        $this->actingAs($h)->postJson("/api/safe-speak/reports/{$r['id']}/messages", ['body' => 'Thanks'])->assertCreated();

        $handlerView = $this->actingAs($h)->getJson("/api/safe-speak/reports/{$r['id']}")->assertOk()->json('data');
        $this->assertSame(['id', 'category', 'subject', 'status', 'created_on', 'updated_on', 'messages'], array_keys($handlerView));
        $this->assertSame(['author', 'body', 'created_on'], array_keys($handlerView['messages'][0]));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $handlerView['created_on'], 'date only, no time of day');

        $reporterView = (string) json_encode($this->postJson('/api/safe-speak/public/follow-up', ['code' => $r['code']])->assertOk()->json('data'));
        foreach (['Synthetic Handler Name', 'handler@example.test', '"id"', 'handler_id'] as $needle) {
            $this->assertStringNotContainsString($needle, $reporterView);
        }
        // Audit keeps the handler id on the handler's message only.
        $this->assertSame([null, $h->id], DB::table('safe_speak_messages')->orderBy('id')->pluck('handler_id')->all());
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function invalidPublic(): iterable
    {
        yield 'submit without body' => ['reports', ['category' => 'fraud', 'subject' => 's'], 'body'];
        yield 'submit unknown category' => ['reports', ['category' => 'gossip', 'subject' => 's', 'body' => 'b'], 'category'];
        yield 'submit subject too long' => ['reports', ['category' => 'fraud', 'subject' => str_repeat('s', 201), 'body' => 'b'], 'subject'];
        yield 'follow-up without code' => ['follow-up', [], 'code'];
        yield 'follow-up with body is prohibited' => ['follow-up', ['code' => 'AAAA-BBBB-CCCC-DDDD', 'body' => 'x'], 'body'];
        yield 'reply without body' => ['reply', ['code' => 'AAAA-BBBB-CCCC-DDDD'], 'body'];
        yield 'code too long' => ['follow-up', ['code' => str_repeat('A', 41)], 'code'];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidPublic')]
    public function test_public_validation(string $path, array $body, string $field): void
    {
        $this->postJson("/api/safe-speak/public/$path", $body)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_reply_with_wrong_code_is_404_and_adds_nothing(): void
    {
        $this->submit();
        $this->postJson('/api/safe-speak/public/reply', ['code' => 'ZZZZ-ZZZZ-ZZZZ-ZZZZ', 'body' => 'x'])->assertNotFound()->assertJsonPath('code', 'invalid_code');
        $this->postJson('/api/safe-speak/public/follow-up', ['code' => 'not a code'])->assertNotFound();
        $this->assertSame(1, DB::table('safe_speak_messages')->count());
    }

    public function test_access_code_hmac_is_bound_to_the_app_key(): void
    {
        $r = $this->submit();
        $stored = (string) SafeSpeakReport::query()->findOrFail($r['id'])->access_code_hash;
        $this->assertSame(64, strlen($stored));
        $this->assertNotSame(hash('sha256', AccessCode::normalize($r['code'])), $stored, 'not a plain hash');
        $this->assertNotSame(AccessCode::hash($r['code'], 'another-key'), $stored);

        // Rotating APP_KEY makes the old code unusable (the controller is built with the key).
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance(SafeSpeakService::class);
        $this->app->forgetInstance(PublicReportController::class);
        $this->postJson('/api/safe-speak/public/follow-up', ['code' => $r['code']])->assertNotFound();
    }

    public function test_confusable_characters_resolve_to_the_same_code(): void
    {
        $r = $this->submit();
        $variant = strtolower(strtr($r['code'], ['0' => 'O', '1' => 'I']));
        $this->postJson('/api/safe-speak/public/follow-up', ['code' => $variant])->assertOk()->assertJsonPath('data.subject', 'Invoice padding');
    }

    public function test_public_routes_reject_get(): void
    {
        foreach (['reports', 'follow-up', 'reply'] as $path) {
            $this->getJson("/api/safe-speak/public/$path")->assertStatus(405);
        }
    }
}
