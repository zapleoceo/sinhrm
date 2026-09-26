<?php

declare(strict_types=1);

namespace Tests\Feature\HiringRequests;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\HiringRequests\Models\HiringRequest;
use App\Modules\HiringRequests\Models\HiringSettings;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFixtures;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/**
 * Hiring requests — every route: 401, hiring-manage per role, contextual access (manager, role approver, vacancy
 * recruiter), 404, 422 with string params, status machine (409), link-vacancy invariants, atomic settings update.
 */
final class HiringRequestRoutesTest extends TestCase
{
    use PeopleFixtures, RecruitingFixtures, RefreshDatabase {
        PeopleFixtures::login insteadof RecruitingFixtures;
    }

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->branch = Branch::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function payload(array $over = []): array
    {
        return $over + ['title' => 'Support Engineer', 'branch_id' => $this->branch->id, 'headcount' => 1, 'reason' => 'new_position'];
    }

    /** Approved request (admin-only route, auto-vacancy off). */
    private function approved(User $admin): int
    {
        HiringSettings::query()->firstOrFail()->update(['auto_vacancy' => false]);
        $id = (int) $this->actingAs($admin)->postJson('/api/hiring-requests', $this->payload(['submit' => true]))->assertCreated()->json('data.id');
        foreach (HiringRequest::query()->findOrFail($id)->approvals as $step) {
            if ($step->status->value === 'pending') {
                $this->actingAs($admin)->postJson("/api/hiring-requests/$id/decision", ['decision' => 'approve'])->assertOk();
            }
        }
        $this->assertSame('approved', HiringRequest::query()->findOrFail($id)->status->value);

        return $id;
    }

    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        yield 'index' => ['GET', '/api/hiring-requests'];
        yield 'inbox' => ['GET', '/api/hiring-requests/inbox'];
        yield 'meta' => ['GET', '/api/hiring-requests/meta'];
        yield 'store' => ['POST', '/api/hiring-requests'];
        yield 'show' => ['GET', '/api/hiring-requests/1'];
        yield 'update' => ['PATCH', '/api/hiring-requests/1'];
        yield 'submit' => ['POST', '/api/hiring-requests/1/submit'];
        yield 'decide' => ['POST', '/api/hiring-requests/1/decision'];
        yield 'cancel' => ['POST', '/api/hiring-requests/1/cancel'];
        yield 'close' => ['POST', '/api/hiring-requests/1/close'];
        yield 'vacancy' => ['POST', '/api/hiring-requests/1/vacancy'];
        yield 'link' => ['POST', '/api/hiring-requests/1/link-vacancy'];
        yield 'settings' => ['GET', '/api/hiring-requests/settings'];
        yield 'settings.update' => ['PUT', '/api/hiring-requests/settings'];
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
    public function test_global_roles(UserRole $role, bool $hr): void
    {
        $admin = $this->login(UserRole::Admin);
        $id = $this->approved($admin);
        $user = $this->login($role);

        $this->actingAs($user)->getJson('/api/hiring-requests/meta')->assertOk()->assertJsonPath('data.can_create', $hr)->assertJsonPath('data.can_manage', $hr);
        $store = $this->actingAs($user)->postJson('/api/hiring-requests', $this->payload());
        $show = $this->actingAs($user)->getJson("/api/hiring-requests/$id");
        $settings = $this->actingAs($user)->getJson('/api/hiring-requests/settings');
        $put = $this->actingAs($user)->putJson('/api/hiring-requests/settings', ['auto_vacancy' => '1']);
        $vacancy = $this->actingAs($user)->postJson("/api/hiring-requests/$id/vacancy", ['recruiter_id' => (string) $admin->id]);
        $close = $this->actingAs($user)->postJson("/api/hiring-requests/$id/close");
        if ($hr) {
            $store->assertCreated();
            $show->assertOk();
            $settings->assertOk();
            $put->assertOk()->assertJsonPath('data.auto_vacancy', true);
            $vacancy->assertOk()->assertJsonPath('data.status', 'in_progress');
            $close->assertOk()->assertJsonPath('data.status', 'closed');
        } else {
            $store->assertForbidden();
            $show->assertNotFound();
            $settings->assertForbidden();
            $put->assertForbidden();
            $vacancy->assertForbidden();
            $close->assertForbidden();
        }
    }

    public function test_role_step_approver_sees_only_from_the_active_step(): void
    {
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->putJson('/api/hiring-requests/settings', ['route' => [
            ['name' => 'Manager', 'kind' => 'manager', 'sla_days' => 2],
            ['name' => 'Recruiting lead', 'kind' => 'role', 'role' => 'recruiter', 'sla_days' => '3'],
        ]])->assertOk();
        $org = $this->org();
        $lead = $this->userOf($org['lead']);
        $recruiter = $this->login(UserRole::Recruiter);
        $id = $this->actingAs($lead)->postJson('/api/hiring-requests', $this->payload(['submit' => true]))->assertCreated()->json('data.id');

        // Step 2 is still waiting: the role holder does not see it yet.
        $this->actingAs($recruiter)->getJson("/api/hiring-requests/$id")->assertNotFound();
        $this->actingAs($recruiter)->postJson("/api/hiring-requests/$id/decision", ['decision' => 'approve'])->assertNotFound();
        $this->actingAs($this->userOf($org['head']))->postJson("/api/hiring-requests/$id/decision", ['decision' => 'approve'])->assertOk();
        $this->actingAs($recruiter)->getJson("/api/hiring-requests/$id")->assertOk()->assertJsonPath('data.can.decide', true)
            ->assertJsonPath('data.approvals.1.due_at', '2026-10-08T09:00:00+00:00');
        $this->actingAs($recruiter)->getJson('/api/hiring-requests/inbox')->assertOk()->assertJsonCount(1, 'data');
        // A viewer (no such role) still gets 404.
        $this->actingAs($this->login(UserRole::Viewer))->getJson("/api/hiring-requests/$id")->assertNotFound();
    }

    public function test_vacancy_recruiter_sees_the_request(): void
    {
        $admin = $this->login(UserRole::Admin);
        $id = $this->approved($admin);
        $recruiter = $this->login(UserRole::Recruiter);
        $this->actingAs($recruiter)->getJson("/api/hiring-requests/$id")->assertNotFound();
        $this->actingAs($admin)->postJson("/api/hiring-requests/$id/vacancy", ['recruiter_id' => $recruiter->id])->assertOk();
        $this->actingAs($recruiter)->getJson("/api/hiring-requests/$id")->assertOk()->assertJsonPath('data.can.manage', false);
        $this->actingAs($recruiter)->getJson('/api/hiring-requests')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_unknown_request_is_404(): void
    {
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->getJson('/api/hiring-requests/999999')->assertNotFound();
        $this->actingAs($admin)->patchJson('/api/hiring-requests/999999', ['title' => 'x'])->assertNotFound();
        $this->actingAs($admin)->postJson('/api/hiring-requests/999999/submit')->assertNotFound();
        $this->actingAs($admin)->postJson('/api/hiring-requests/999999/decision', ['decision' => 'approve'])->assertNotFound();
        $this->actingAs($admin)->postJson('/api/hiring-requests/999999/cancel')->assertNotFound();
        $this->actingAs($admin)->postJson('/api/hiring-requests/999999/close')->assertNotFound();
        $this->actingAs($admin)->postJson('/api/hiring-requests/999999/vacancy')->assertNotFound();
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'no title' => [['title' => null], 'title'];
        yield 'headcount zero' => [['headcount' => 0], 'headcount'];
        yield 'headcount over 500' => [['headcount' => '501'], 'headcount'];
        yield 'bad reason' => [['reason' => 'growth'], 'reason'];
        yield 'bad priority' => [['priority' => 'asap'], 'priority'];
        yield 'currency length' => [['currency' => 'US'], 'currency'];
        yield 'date format' => [['desired_start_date' => '01.11.2026'], 'desired_start_date'];
        yield 'unknown branch' => [['branch_id' => 999999], 'branch_id'];
        yield 'branch as text' => [['branch_id' => 'kyiv'], 'branch_id'];
    }

    /** @param array<string, mixed> $over */
    #[DataProvider('invalidPayloads')]
    public function test_create_validation(array $over, string $field): void
    {
        $body = array_filter($over + $this->payload(), static fn (mixed $v): bool => $v !== null);
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/hiring-requests', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_strings_are_accepted_for_numbers(): void
    {
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/hiring-requests', $this->payload([
            'branch_id' => (string) $this->branch->id, 'headcount' => '3', 'salary_min' => '1000', 'salary_max' => '999.5',
        ]))->assertUnprocessable()->assertJsonPath('code', 'salary_range');
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/hiring-requests', $this->payload([
            'branch_id' => (string) $this->branch->id, 'headcount' => '3', 'salary_min' => '1000', 'salary_max' => '1500',
        ]))->assertCreated()->assertJsonPath('data.headcount', 3);
    }

    public function test_list_filters_and_decision_validation(): void
    {
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->postJson('/api/hiring-requests', $this->payload())->assertCreated();
        $id = $this->actingAs($admin)->postJson('/api/hiring-requests', $this->payload(['submit' => true]))->assertCreated()->json('data.id');

        $this->actingAs($admin)->getJson('/api/hiring-requests?status=draft')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/hiring-requests?status=pending&mine=1')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/hiring-requests?status=open')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($admin)->getJson('/api/hiring-requests?mine=yes')->assertUnprocessable();
        $this->actingAs($admin)->postJson("/api/hiring-requests/$id/decision", [])->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->actingAs($admin)->postJson("/api/hiring-requests/$id/decision", ['decision' => 'approve', 'recruiter_id' => 'abc'])->assertUnprocessable();
        $this->actingAs($admin)->postJson("/api/hiring-requests/$id/submit")->assertStatus(409)->assertJsonPath('code', 'invalid_status');
        $this->actingAs($admin)->postJson("/api/hiring-requests/$id/close")->assertStatus(409);
        $this->actingAs($admin)->postJson("/api/hiring-requests/$id/vacancy")->assertStatus(409);
    }

    public function test_draft_is_edited_only_by_requester_or_hr(): void
    {
        $org = $this->org();
        $lead = $this->userOf($org['lead']);
        $id = $this->actingAs($lead)->postJson('/api/hiring-requests', $this->payload())->assertCreated()->json('data.id');
        $this->actingAs($this->login(UserRole::HrManager))->patchJson("/api/hiring-requests/$id", ['title' => 'By HR'])->assertOk()->assertJsonPath('data.title', 'By HR');
        $this->actingAs($lead)->postJson("/api/hiring-requests/$id/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($lead)->postJson("/api/hiring-requests/$id/cancel")->assertStatus(409);
    }

    public function test_link_vacancy_validation_and_taken(): void
    {
        $admin = $this->login(UserRole::Admin);
        $first = $this->approved($admin);
        $second = $this->approved($admin);
        $vacancy = $this->vacancyIn($this->branch);

        $this->actingAs($admin)->postJson("/api/hiring-requests/$first/link-vacancy", [])->assertUnprocessable()->assertJsonValidationErrors('vacancy_id');
        $this->actingAs($admin)->postJson("/api/hiring-requests/$first/link-vacancy", ['vacancy_id' => 999999])->assertUnprocessable();
        $this->actingAs($admin)->postJson("/api/hiring-requests/$first/link-vacancy", ['vacancy_id' => (string) $vacancy->id])->assertOk()
            ->assertJsonPath('data.vacancy.id', $vacancy->id);
        $this->actingAs($admin)->postJson("/api/hiring-requests/$second/link-vacancy", ['vacancy_id' => $vacancy->id])->assertStatus(409)->assertJsonPath('code', 'vacancy_taken');
    }

    /**
     * Regression: the API linked ANY vacancy — a closed one (the SLA job then closed the fresh request at once) or a
     * vacancy of another branch. The rule (docs/modules/hiring-requests.md) is "an existing OPEN vacancy of the
     * request's branch"; only the frontend filtered the choice.
     */
    public function test_link_vacancy_requires_an_open_vacancy_of_the_same_branch(): void
    {
        $admin = $this->login(UserRole::Admin);
        $id = $this->approved($admin);
        $closed = $this->vacancyIn($this->branch);
        $closed->update(['status' => 'closed']);
        $elsewhere = $this->vacancyIn(Branch::factory()->create());

        $this->actingAs($admin)->postJson("/api/hiring-requests/$id/link-vacancy", ['vacancy_id' => $closed->id])
            ->assertUnprocessable()->assertJsonPath('code', 'vacancy_not_linkable');
        $this->actingAs($admin)->postJson("/api/hiring-requests/$id/link-vacancy", ['vacancy_id' => $elsewhere->id])
            ->assertUnprocessable()->assertJsonPath('code', 'vacancy_not_linkable');
        $this->assertSame('approved', HiringRequest::query()->findOrFail($id)->status->value);
        $this->assertNull(HiringRequest::query()->findOrFail($id)->vacancy_id);
        $this->assertSame(2, Vacancy::query()->whereIn('id', [$closed->id, $elsewhere->id])->count());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidSettings(): iterable
    {
        yield 'bad field key' => [['form_fields' => [['key' => 'Bad Key', 'label' => 'x', 'type' => 'text']]]];
        yield 'bad field type' => [['form_fields' => [['key' => 'a', 'label' => 'x', 'type' => 'file']]]];
        yield 'select without options' => [['form_fields' => [['key' => 'a', 'label' => 'x', 'type' => 'select']]]];
        yield 'unknown creator' => [['creator_user_ids' => [999999]]];
        yield 'empty route' => [['route' => []]];
        yield 'sla over 60' => [['route' => [['name' => 'HR', 'kind' => 'role', 'role' => 'admin', 'sla_days' => 61]]]];
        yield 'bad kind' => [['route' => [['name' => 'HR', 'kind' => 'committee']]]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidSettings')]
    public function test_settings_validation(array $body): void
    {
        $this->actingAs($this->login(UserRole::Admin))->putJson('/api/hiring-requests/settings', $body)->assertUnprocessable();
    }

    /** Regression: a 422 on the route used to leave the other settings of the same request already saved. */
    public function test_settings_update_is_atomic_when_the_route_is_invalid(): void
    {
        $admin = $this->login(UserRole::Admin);
        $this->assertTrue((bool) HiringSettings::query()->firstOrFail()->auto_vacancy);
        $this->actingAs($admin)->putJson('/api/hiring-requests/settings', [
            'auto_vacancy' => false,
            'creator_user_ids' => [$admin->id],
            'route' => [['name' => 'Ghost', 'kind' => 'user', 'user_id' => 999999]],
        ])->assertUnprocessable()->assertJsonPath('code', 'invalid_route');

        $settings = HiringSettings::query()->firstOrFail();
        $this->assertTrue((bool) $settings->auto_vacancy, 'nothing changes when the request is rejected');
        $this->assertSame([], $settings->creator_user_ids ?? []);
    }

    public function test_route_user_step_for_blocked_user_is_invalid(): void
    {
        $blocked = User::factory()->blocked()->withRole(UserRole::Admin)->create();
        $this->actingAs($this->login(UserRole::Admin))->putJson('/api/hiring-requests/settings', ['route' => [['name' => 'X', 'kind' => 'user', 'user_id' => $blocked->id]]])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_route');
    }
}
