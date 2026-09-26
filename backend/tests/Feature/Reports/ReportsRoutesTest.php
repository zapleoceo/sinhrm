<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Reports\Models\SavedReport;
use App\Modules\Reports\Services\SavedReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PeopleFixtures;
use Tests\TestCase;

/**
 * Reports — every route: 401, availability per role, 404 for unknown/foreign, 422 for filters arriving as strings,
 * builder whitelist limits (columns/filters count, LIKE wildcards are literal), CSV formula guard on every CSV route,
 * saved reports limit and re-check of rights at run time.
 */
final class ReportsRoutesTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        yield 'catalog' => ['GET', '/api/reports/catalog'];
        yield 'run' => ['GET', '/api/reports/catalog/headcount'];
        yield 'csv' => ['GET', '/api/reports/catalog/headcount/csv'];
        yield 'datasets' => ['GET', '/api/reports/builder/datasets'];
        yield 'build' => ['POST', '/api/reports/builder/run'];
        yield 'build.csv' => ['POST', '/api/reports/builder/csv'];
        yield 'saved' => ['GET', '/api/reports/saved'];
        yield 'saved.store' => ['POST', '/api/reports/saved'];
        yield 'saved.update' => ['PUT', '/api/reports/saved/1'];
        yield 'saved.destroy' => ['DELETE', '/api/reports/saved/1'];
        yield 'saved.run' => ['GET', '/api/reports/saved/1/run'];
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
    public function test_hr_reports_and_pii_per_role(UserRole $role, bool $hr): void
    {
        $user = $this->login($role);
        $keys = [];
        foreach ((array) $this->actingAs($user)->getJson('/api/reports/catalog')->assertOk()->json('data') as $group) {
            $keys = [...$keys, ...array_column((array) $group['reports'], 'key')];
        }
        $this->assertContains('recruiting_funnel', $keys, 'recruiting reports: every active user (branch scope)');
        $this->assertSame($hr, in_array('age', $keys, true));
        $this->assertSame($hr, in_array('headcount', $keys, true));
        $hr
            ? $this->actingAs($user)->getJson('/api/reports/catalog/age')->assertOk()
            : $this->actingAs($user)->getJson('/api/reports/catalog/age')->assertNotFound();
        $pii = $this->actingAs($user)->postJson('/api/reports/builder/run', ['dataset' => 'employees', 'columns' => ['full_name', 'personal_email']]);
        $hr ? $pii->assertOk() : $pii->assertUnprocessable();
        $csv = $this->actingAs($user)->get('/api/reports/catalog/desk_sla/csv');
        $hr ? $csv->assertOk() : $csv->assertNotFound();
    }

    public function test_catalog_filters_arrive_as_strings(): void
    {
        $branch = Branch::factory()->create();
        $this->employee(['branch_id' => $branch->id]);
        $this->employee();
        $admin = $this->login(UserRole::Admin);

        $this->actingAs($admin)->getJson("/api/reports/catalog/headcount?branch_id={$branch->id}")->assertOk()
            ->assertJsonPath('data.filters.branch_id', $branch->id)->assertJsonPath('data.rows.0.headcount', 1);
        $this->actingAs($admin)->getJson('/api/reports/catalog/headcount?branch_id=abc')->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->actingAs($admin)->getJson('/api/reports/catalog/headcount?branch_id=0')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/reports/catalog/headcount?to=31.12.2026')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->actingAs($admin)->getJson('/api/reports/catalog/hires_terminations?from=2026-13-01')->assertUnprocessable();
        // Filters the report does not declare are ignored, not rejected.
        $this->actingAs($admin)->getJson('/api/reports/catalog/headcount?weeks=zzz&evil=1')->assertOk()->assertJsonPath('data.filters', []);
        // Uppercase / unknown keys never reach a report.
        $this->actingAs($admin)->getJson('/api/reports/catalog/HEADCOUNT')->assertNotFound();
        $this->actingAs($admin)->get('/api/reports/catalog/nope/csv')->assertNotFound();
    }

    public function test_weeks_filter_bounds(): void
    {
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->getJson('/api/reports/catalog/mood_trend?weeks=12')->assertOk()->assertJsonPath('data.filters.weeks', 12);
        $this->actingAs($admin)->getJson('/api/reports/catalog/mood_trend?weeks=53')->assertUnprocessable()->assertJsonValidationErrors('weeks');
        $this->actingAs($admin)->getJson('/api/reports/catalog/mood_trend?weeks=0')->assertUnprocessable();
    }

    public function test_builder_limits(): void
    {
        $admin = $this->login(UserRole::Admin);
        $columns = array_fill(0, 21, 'full_name');
        $this->actingAs($admin)->postJson('/api/reports/builder/run', ['dataset' => 'employees', 'columns' => $columns])->assertUnprocessable();
        $filters = array_fill(0, 11, ['column' => 'full_name', 'op' => 'eq', 'value' => 'x']);
        $this->actingAs($admin)->postJson('/api/reports/builder/run', ['dataset' => 'employees', 'columns' => ['full_name'], 'filters' => $filters])->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/reports/builder/run', ['dataset' => 'employees', 'columns' => []])->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/reports/builder/run', [])->assertUnprocessable()->assertJsonValidationErrors('dataset');
        $this->actingAs($admin)->postJson('/api/reports/builder/run', ['dataset' => 'employees', 'columns' => ['full_name'], 'filters' => [['op' => 'eq']]])
            ->assertUnprocessable()->assertJsonValidationErrors('filters.0.column');
        $this->actingAs($admin)->postJson('/api/reports/builder/run', ['dataset' => 'employees', 'group_by' => 'status', 'aggregate' => ['fn' => 'median']])->assertUnprocessable();
    }

    public function test_contains_treats_like_wildcards_literally(): void
    {
        $this->employee(['full_name' => 'Plain Name']);
        $this->employee(['full_name' => 'Hundred%Percent']);
        $this->employee(['full_name' => 'Under_Score']);
        $admin = $this->login(UserRole::Admin);
        $names = fn (string $needle): array => array_column((array) $this->actingAs($admin)->postJson('/api/reports/builder/run', [
            'dataset' => 'employees', 'columns' => ['full_name'], 'filters' => [['column' => 'full_name', 'op' => 'contains', 'value' => $needle]],
        ])->assertOk()->json('data.rows'), 'full_name');

        $this->assertSame(['Hundred%Percent'], $names('%'));
        $this->assertSame(['Under_Score'], $names('_'));
        $this->assertSame([], $names('!'));
        $this->assertSame(['Plain Name'], $names('PLAIN'));
    }

    public function test_every_csv_route_neutralises_formulas(): void
    {
        $branch = Branch::factory()->create(['name' => '@SUM(1+1)']);
        $this->employee(['full_name' => '-2+3+cmd|calc', 'branch_id' => $branch->id]);
        $admin = $this->login(UserRole::Admin);

        $catalog = $this->actingAs($admin)->get('/api/reports/catalog/headcount/csv')->assertOk();
        $this->assertStringContainsString('no-store', (string) $catalog->headers->get('Cache-Control'));
        $body = $catalog->streamedContent();
        $this->assertStringContainsString("'@SUM(1+1)", $body);
        $this->assertStringNotContainsString("\n@SUM", $body);

        $builder = $this->actingAs($admin)->post('/api/reports/builder/csv', ['dataset' => 'employees', 'columns' => ['full_name', 'branch']], ['Accept' => 'application/json'])
            ->assertOk()->streamedContent();
        $this->assertStringContainsString("'-2+3+cmd|calc", $builder);
        $this->assertStringContainsString("'@SUM(1+1)", $builder);

        $id = $this->actingAs($admin)->postJson('/api/reports/saved', ['name' => 'x', 'kind' => 'builder', 'definition' => ['dataset' => 'employees', 'columns' => ['full_name']]])->json('data.id');
        $saved = $this->actingAs($admin)->get("/api/reports/saved/$id/run?format=csv")->assertOk()->streamedContent();
        $this->assertStringContainsString("'-2+3+cmd|calc", $saved);
        $this->assertStringNotContainsString("\n-2+3", $saved);
    }

    public function test_builder_csv_validation_errors_are_json(): void
    {
        $this->actingAs($this->login(UserRole::Admin))->postJson('/api/reports/builder/csv', ['dataset' => 'payroll', 'columns' => ['x']])
            ->assertUnprocessable()->assertJsonPath('errors.dataset.0', 'unknown_dataset');
    }

    public function test_saved_validation_limit_and_404(): void
    {
        $admin = $this->login(UserRole::Admin);
        $this->actingAs($admin)->postJson('/api/reports/saved', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'kind', 'definition']);
        $this->actingAs($admin)->postJson('/api/reports/saved', ['name' => 'x', 'kind' => 'pivot', 'definition' => ['key' => 'headcount']])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->actingAs($admin)->postJson('/api/reports/saved', ['name' => 'x', 'kind' => 'catalog', 'definition' => ['key' => 'nope']])->assertNotFound();
        $this->actingAs($admin)->putJson('/api/reports/saved/999999', ['name' => 'x', 'kind' => 'catalog', 'definition' => ['key' => 'headcount']])->assertNotFound();
        $this->actingAs($admin)->getJson('/api/reports/saved/999999/run')->assertNotFound();
        $this->actingAs($admin)->deleteJson('/api/reports/saved/999999')->assertNotFound();

        for ($i = 0; $i < SavedReportService::MAX_PER_USER; $i++) {
            SavedReport::query()->create(['user_id' => $admin->id, 'name' => "r$i", 'kind' => 'catalog', 'definition' => ['key' => 'headcount', 'filters' => []]]);
        }
        $this->actingAs($admin)->postJson('/api/reports/saved', ['name' => 'one more', 'kind' => 'catalog', 'definition' => ['key' => 'headcount']])
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'too_many_saved_reports');
        // Updating an existing one is still possible at the limit.
        $first = SavedReport::query()->where('user_id', $admin->id)->value('id');
        $this->actingAs($admin)->putJson("/api/reports/saved/$first", ['name' => 'renamed', 'kind' => 'catalog', 'definition' => ['key' => 'headcount']])->assertOk();
    }

    public function test_foreign_saved_report_cannot_be_updated(): void
    {
        $owner = $this->login(UserRole::Admin);
        $id = $this->actingAs($owner)->postJson('/api/reports/saved', ['name' => 'Mine', 'kind' => 'catalog', 'definition' => ['key' => 'headcount']])->json('data.id');
        $this->actingAs($this->login(UserRole::Admin))->putJson("/api/reports/saved/$id", ['name' => 'Hijack', 'kind' => 'catalog', 'definition' => ['key' => 'headcount']])->assertNotFound();
        $this->assertDatabaseHas('saved_reports', ['id' => $id, 'name' => 'Mine']);
    }

    public function test_saved_report_loses_access_when_the_role_is_revoked(): void
    {
        $user = $this->login(UserRole::Admin);
        $pii = $this->actingAs($user)->postJson('/api/reports/saved', ['name' => 'Emails', 'kind' => 'builder', 'definition' => ['dataset' => 'employees', 'columns' => ['full_name', 'personal_email']]])
            ->assertCreated()->json('data.id');
        $age = $this->actingAs($user)->postJson('/api/reports/saved', ['name' => 'Age', 'kind' => 'catalog', 'definition' => ['key' => 'age']])->assertCreated()->json('data.id');

        $user->syncRoles([UserRole::Viewer->value]);
        $user->refresh();
        $this->actingAs($user)->getJson("/api/reports/saved/$pii/run")->assertUnprocessable();
        $this->actingAs($user)->get("/api/reports/saved/$pii/run?format=csv", ['Accept' => 'application/json'])->assertUnprocessable();
        $this->actingAs($user)->getJson("/api/reports/saved/$age/run")->assertNotFound();
    }
}
