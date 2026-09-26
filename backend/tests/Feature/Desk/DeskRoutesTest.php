<?php

declare(strict_types=1);

namespace Tests\Feature\Desk;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Desk\Models\DeskCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * Desk — every route: 401 for guests, desk-manage gate per role, 404 on unknown/foreign ids (incl. an attachment
 * of another case), 422 validation with string params, SLA late-response semantics, file count limit.
 */
final class DeskRoutesTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private const string PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $attributes */
    private function category(array $attributes = []): DeskCategory
    {
        return DeskCategory::query()->create($attributes + ['name' => 'Access cards', 'first_response_hours' => 4, 'resolve_hours' => 48, 'active' => true]);
    }

    /** @return array{User, int} */
    private function openCase(?DeskCategory $category = null): array
    {
        $user = $this->login(UserRole::Employee);
        $this->employee([], $user);
        $id = (int) $this->actingAs($user)->postJson('/api/desk/cases', [
            'category_id' => (string) ($category ?? $this->category())->id, 'subject' => 'Card', 'body' => 'Broken',
        ])->assertCreated()->json('data.id');

        return [$user, $id];
    }

    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        yield 'categories' => ['GET', '/api/desk/categories'];
        yield 'mine' => ['GET', '/api/desk/cases/mine'];
        yield 'store' => ['POST', '/api/desk/cases'];
        yield 'show' => ['GET', '/api/desk/cases/1'];
        yield 'update' => ['PATCH', '/api/desk/cases/1'];
        yield 'comment' => ['POST', '/api/desk/cases/1/comments'];
        yield 'attach' => ['POST', '/api/desk/cases/1/attachments'];
        yield 'download' => ['GET', '/api/desk/cases/1/attachments/1'];
        yield 'queue' => ['GET', '/api/desk/cases'];
        yield 'category.store' => ['POST', '/api/desk/categories'];
        yield 'category.update' => ['PATCH', '/api/desk/categories/1'];
    }

    #[DataProvider('routes')]
    public function test_guest_gets_401(string $method, string $uri): void
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
    public function test_queue_and_categories_follow_desk_manage(UserRole $role, bool $hr): void
    {
        $category = $this->category();
        [, $id] = $this->openCase($category);
        $user = $this->login($role);

        $queue = $this->actingAs($user)->getJson('/api/desk/cases');
        $store = $this->actingAs($user)->postJson('/api/desk/categories', ['name' => 'Benefits', 'first_response_hours' => '8', 'resolve_hours' => '72']);
        $update = $this->actingAs($user)->patchJson("/api/desk/categories/{$category->id}", ['active' => false]);
        $show = $this->actingAs($user)->getJson("/api/desk/cases/$id");
        if ($hr) {
            $queue->assertOk()->assertJsonCount(1, 'data');
            $store->assertCreated()->assertJsonPath('data.first_response_hours', 8)->assertJsonPath('data.resolve_hours', 72);
            $update->assertOk()->assertJsonPath('data.active', false);
            $show->assertOk()->assertJsonPath('data.can_manage', true);
        } else {
            $queue->assertForbidden();
            $store->assertForbidden();
            $update->assertForbidden();
            $show->assertNotFound();
        }
    }

    public function test_inactive_categories_listed_only_for_hr_with_all(): void
    {
        $this->category(['name' => 'Active one']);
        $this->category(['name' => 'Old one', 'active' => false]);
        $admin = $this->login(UserRole::Admin);
        $employee = $this->login(UserRole::Employee);

        $this->actingAs($employee)->getJson('/api/desk/categories?all=1')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/desk/categories')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/desk/categories?all=1')->assertOk()->assertJsonCount(2, 'data');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidCategories(): iterable
    {
        yield 'no name' => [['first_response_hours' => 4], 'name'];
        yield 'zero hours' => [['name' => 'x', 'first_response_hours' => 0], 'first_response_hours'];
        yield 'hours above 90 days' => [['name' => 'x', 'resolve_hours' => 2161], 'resolve_hours'];
        yield 'fractional hours' => [['name' => 'x', 'resolve_hours' => '1.5'], 'resolve_hours'];
        yield 'name too long' => [['name' => str_repeat('n', 121)], 'name'];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidCategories')]
    public function test_category_validation(array $body, string $field): void
    {
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/desk/categories', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_category_assignee_must_be_hr_and_unknown_category_is_404(): void
    {
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->postJson('/api/desk/categories', ['name' => 'x', 'default_assignee_id' => $this->login(UserRole::Recruiter)->id])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_assignee');
        $hr = $this->login(UserRole::HrManager);
        $category = $this->actingAs($admin)->postJson('/api/desk/categories', ['name' => 'Routed', 'default_assignee_id' => (string) $hr->id])->assertCreated()->json('data.id');
        $this->actingAs($admin)->patchJson('/api/desk/categories/999999', ['name' => 'x'])->assertNotFound();

        // New cases in the category land on the default assignee.
        [, $id] = $this->openCase(DeskCategory::query()->findOrFail($category));
        $this->actingAs($admin)->getJson("/api/desk/cases/$id")->assertOk()->assertJsonPath('data.assignee.id', $hr->id);
    }

    public function test_open_case_validation(): void
    {
        $user = $this->login(UserRole::Employee);
        $this->employee([], $user);
        $inactive = $this->category(['active' => false]);
        $this->actingAs($user)->postJson('/api/desk/cases', [])->assertUnprocessable()->assertJsonValidationErrors(['category_id', 'subject', 'body']);
        $this->actingAs($user)->postJson('/api/desk/cases', ['category_id' => 'abc', 'subject' => 's', 'body' => 'b'])->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->actingAs($user)->postJson('/api/desk/cases', ['category_id' => $inactive->id, 'subject' => 's', 'body' => 'b'])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/desk/cases', ['category_id' => 999999, 'subject' => 's', 'body' => 'b'])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/desk/cases', ['category_id' => $this->category()->id, 'subject' => str_repeat('s', 201), 'body' => 'b'])
            ->assertUnprocessable()->assertJsonValidationErrors('subject');
    }

    public function test_queue_filters_arrive_as_strings(): void
    {
        $a = $this->category(['name' => 'A']);
        $b = $this->category(['name' => 'B']);
        [, $first] = $this->openCase($a);
        [, $second] = $this->openCase($b);
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->patchJson("/api/desk/cases/$second", ['status' => 'closed'])->assertOk();

        $this->actingAs($admin)->getJson("/api/desk/cases?category_id={$a->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first);
        $this->actingAs($admin)->getJson('/api/desk/cases?open=1')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/desk/cases?status=closed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second);
        $this->actingAs($admin)->getJson('/api/desk/cases?status=open')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($admin)->getJson('/api/desk/cases?category_id=x')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/desk/cases?open=maybe')->assertUnprocessable();
    }

    public function test_update_and_comment_validation_and_404(): void
    {
        [$owner, $id] = $this->openCase();
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->patchJson('/api/desk/cases/999999', ['status' => 'closed'])->assertNotFound();
        $this->actingAs($admin)->postJson('/api/desk/cases/999999/comments', ['body' => 'x'])->assertNotFound();
        $this->actingAs($admin)->patchJson("/api/desk/cases/$id", ['status' => 'archived'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($admin)->patchJson("/api/desk/cases/$id", ['category_id' => 999999])->assertNotFound();
        $this->actingAs($admin)->postJson("/api/desk/cases/$id/comments", [])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->actingAs($admin)->postJson("/api/desk/cases/$id/comments", ['body' => 'x', 'internal' => 'yes'])->assertUnprocessable()->assertJsonValidationErrors('internal');
        // The requester can only close, not reopen or re-categorise.
        $this->actingAs($owner)->patchJson("/api/desk/cases/$id", ['status' => 'resolved'])->assertForbidden();
        $this->actingAs($owner)->patchJson("/api/desk/cases/$id", ['category_id' => $this->category()->id])->assertForbidden();
        $this->actingAs($owner)->postJson("/api/desk/cases/$id/comments", ['body' => 'x', 'article_id' => 1])->assertForbidden();
    }

    public function test_late_first_response_stays_breached_and_timely_resolution_does_not(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        [, $id] = $this->openCase($this->category(['first_response_hours' => 2, 'resolve_hours' => 24]));
        $admin = $this->login(UserRole::Admin);

        Carbon::setTestNow('2026-10-05 10:59:00');
        $this->actingAs($admin)->getJson("/api/desk/cases/$id")->assertJsonPath('data.sla.first_response_breached', false);
        Carbon::setTestNow('2026-10-05 12:00:00');
        $this->actingAs($admin)->postJson("/api/desk/cases/$id/comments", ['body' => 'Sorry for the delay'])->assertCreated()
            ->assertJsonPath('data.sla.first_response_breached', true);
        $this->actingAs($admin)->patchJson("/api/desk/cases/$id", ['status' => 'resolved'])->assertOk()->assertJsonPath('data.sla.resolve_breached', false);
        // Much later, the resolved case is still within its target: the clock stopped at resolution.
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->actingAs($admin)->getJson("/api/desk/cases/$id")->assertJsonPath('data.sla.resolve_breached', false);
        // Reopening restarts from the original opening: now far past the 24 h target.
        $this->actingAs($admin)->patchJson("/api/desk/cases/$id", ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.sla.resolve_breached', true);
    }

    public function test_category_without_targets_never_breaches(): void
    {
        Carbon::setTestNow('2026-10-05 09:00:00');
        [, $id] = $this->openCase($this->category(['first_response_hours' => null, 'resolve_hours' => null]));
        Carbon::setTestNow('2027-01-01 09:00:00');
        $this->actingAs($this->login(UserRole::Admin))->getJson("/api/desk/cases/$id")->assertOk()
            ->assertJsonPath('data.sla.first_response_breached', false)->assertJsonPath('data.sla.resolve_breached', false)
            ->assertJsonPath('data.sla.first_response_due', null);
    }

    public function test_attachment_of_another_case_is_404_and_ten_files_max(): void
    {
        [$owner, $mine] = $this->openCase();
        $admin = $this->login(UserRole::Admin);
        [, $foreign] = $this->openCase();
        $upload = fn (User $u, int $case) => $this->actingAs($u)->post("/api/desk/cases/$case/attachments", ['file' => UploadedFile::fake()->createWithContent('scan.pdf', self::PDF)], ['Accept' => 'application/json']);

        $foreignFile = (int) $upload($admin, $foreign)->assertCreated()->json('data.attachments.0.id');
        // The owner of another case cannot fetch it through their own case id (IDOR).
        $this->actingAs($owner)->get("/api/desk/cases/$mine/attachments/$foreignFile")->assertNotFound();
        $this->actingAs($admin)->get("/api/desk/cases/$mine/attachments/$foreignFile")->assertNotFound();
        $this->actingAs($owner)->get("/api/desk/cases/$mine/attachments/999999")->assertNotFound();
        $this->actingAs($owner)->post("/api/desk/cases/$mine/attachments", [], ['Accept' => 'application/json'])->assertUnprocessable();

        for ($i = 0; $i < 10; $i++) {
            $upload($owner, $mine)->assertCreated();
        }
        $upload($owner, $mine)->assertUnprocessable();
        // Closed case: no more files.
        $this->actingAs($owner)->patchJson("/api/desk/cases/$foreign", ['status' => 'closed'])->assertNotFound();
        $this->actingAs($admin)->patchJson("/api/desk/cases/$foreign", ['status' => 'closed'])->assertOk();
        $upload($admin, $foreign)->assertStatus(409);
    }
}
