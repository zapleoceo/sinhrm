<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Directory\Models\Branch;
use App\Modules\Pulse\Models\SurveyWave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Support\PeopleFixtures;
use Tests\Support\PulseFixtures;
use Tests\Support\RecruitingFixtures;
use Tests\TestCase;

/** Reports: catalog availability, scoping, PII gating, builder whitelist, CSV (injection-safe), saved reports. */
final class ReportsApiTest extends TestCase
{
    use PeopleFixtures, PulseFixtures, RecruitingFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     * @return list<string>
     */
    private function keys(TestResponse $response): array
    {
        $keys = [];
        foreach ((array) $response->json('data') as $group) {
            foreach ((array) $group['reports'] as $r) {
                $keys[] = (string) $r['key'];
            }
        }

        return $keys;
    }

    public function test_catalog_depends_on_the_user(): void
    {
        $this->getJson('/api/reports/catalog')->assertUnauthorized();
        $org = $this->org();
        $admin = $this->login(UserRole::Admin);

        $all = $this->keys($this->actingAs($admin)->getJson('/api/reports/catalog')->assertOk());
        $this->assertCount(20, $all);
        $this->assertNotContains('gender_pay_gap', $all, 'no salary data → no pay gap report');

        $manager = $this->keys($this->actingAs($this->userOf($org['lead']))->getJson('/api/reports/catalog'));
        $this->assertContains('headcount', $manager);
        $this->assertContains('mood_trend', $manager);
        foreach (['age', 'desk_sla', 'assets_by_status', 'enps_trend'] as $adminOnly) {
            $this->assertNotContains($adminOnly, $manager);
        }
        $worker = $this->keys($this->actingAs($this->userOf($org['worker']))->getJson('/api/reports/catalog'));
        $this->assertNotContains('headcount', $worker);
        $this->assertContains('recruiting_funnel', $worker, 'recruiting reports follow the branch scope, like /reports');

        $this->actingAs($this->userOf($org['lead']))->getJson('/api/reports/catalog/age')->assertNotFound();
        $this->actingAs($this->userOf($org['worker']))->getJson('/api/reports/catalog/headcount')->assertNotFound();
        $this->actingAs($admin)->getJson('/api/reports/catalog/nope')->assertNotFound();
    }

    public function test_hr_reports_are_scoped_to_the_people_scope(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $org = $this->org();
        $admin = $this->login(UserRole::Admin);
        $this->employee(['full_name' => 'Gone Person', 'hired_at' => '2026-02-10', 'fired_at' => '2026-06-15', 'status' => 'terminated']);

        $total = fn ($user): int => array_sum(array_column((array) $this->actingAs($user)->getJson('/api/reports/catalog/headcount')->assertOk()->json('data.rows'), 'headcount'));
        $this->assertSame(5, $total($admin));
        $this->assertSame(3, $total($this->userOf($org['lead'])), 'lead + worker + peer');

        $rows = $this->actingAs($admin)->getJson('/api/reports/catalog/hires_terminations?from=2026-01-01&to=2026-12-31')->assertOk()->json('data.rows');
        $this->assertIsArray($rows);
        $this->assertCount(12, $rows);
        $this->assertSame(['month' => '2026-06', 'hires' => 0, 'terminations' => 1], $rows[5]);

        $this->actingAs($admin)->getJson('/api/reports/catalog/hires_terminations?from=2026-12-01&to=2026-01-01')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/reports/catalog/turnover?from=2026-06-01&to=2026-06-30')->assertOk()
            ->assertJsonPath('data.rows.0.terminations', 1)->assertJsonPath('data.rows.1.month', 'total');
    }

    public function test_age_report_is_pii_and_admin_only(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $org = $this->org();
        $admin = $this->login(UserRole::Admin);
        $rows = $this->actingAs($admin)->getJson('/api/reports/catalog/age')->assertOk()->json('data.rows');
        $this->assertEquals(['<25' => 0, '25-34' => 0, '35-44' => 1, '45-54' => 0, '55+' => 0, 'unknown' => 4], array_column((array) $rows, 'employees', 'bucket'));
        $this->actingAs($this->userOf($org['head']))->getJson('/api/reports/catalog/age')->assertNotFound();
    }

    public function test_recruiting_reports_follow_the_branch_scope(): void
    {
        $kyiv = Branch::query()->create(['name' => 'Kyiv test', 'status' => 'active']);
        $lviv = Branch::query()->create(['name' => 'Lviv test', 'status' => 'active']);
        $this->applied($this->vacancyIn($kyiv), ['full_name' => 'Kyiv Candidate']);
        $this->applied($this->vacancyIn($lviv), ['full_name' => 'Lviv Candidate']);
        $recruiter = $this->userWith(UserRole::Recruiter, [$kyiv]);
        $admin = $this->login(UserRole::Admin);

        $count = fn ($user): int => array_sum(array_column((array) $this->actingAs($user)->getJson('/api/reports/catalog/recruiting_funnel')->assertOk()->json('data.rows'), 'applications'));
        $this->assertSame(2, $count($admin));
        $this->assertSame(1, $count($recruiter));

        $names = array_column((array) $this->actingAs($recruiter)->postJson('/api/reports/builder/run', ['dataset' => 'applications', 'columns' => ['candidate', 'branch']])
            ->assertOk()->json('data.rows'), 'candidate');
        $this->assertSame(['Kyiv Candidate'], $names);
    }

    public function test_builder_whitelist_pii_and_grouping(): void
    {
        $org = $this->org();
        $admin = $this->login(UserRole::Admin);
        $lead = $this->userOf($org['lead']);
        $run = fn ($user, array $spec): TestResponse => $this->actingAs($user)->postJson('/api/reports/builder/run', $spec);

        $run($admin, ['dataset' => 'employees', 'columns' => ['full_name', 'salary']])->assertUnprocessable()->assertJsonFragment(['columns.1' => ['unknown_column']]);
        $run($admin, ['dataset' => 'employees', 'columns' => ['e.full_name; drop table users']])->assertUnprocessable();
        $run($admin, ['dataset' => 'payroll', 'columns' => ['x']])->assertUnprocessable()->assertJsonPath('errors.dataset.0', 'unknown_dataset');
        $run($admin, ['dataset' => 'employees', 'columns' => ['full_name'], 'filters' => [['column' => 'full_name', 'op' => 'union', 'value' => 'x']]])->assertUnprocessable();
        $run($admin, ['dataset' => 'employees', 'group_by' => 'branch', 'aggregate' => ['fn' => 'sum', 'column' => 'full_name']])->assertUnprocessable();
        $run($lead, ['dataset' => 'employees', 'columns' => ['full_name', 'birth_date']])->assertUnprocessable()->assertJsonFragment(['columns.1' => ['pii_forbidden']]);
        $run($lead, ['dataset' => 'assets', 'columns' => ['name']])->assertUnprocessable();
        $run($this->userOf($org['worker']), ['dataset' => 'employees', 'columns' => ['full_name']])->assertUnprocessable();

        // Manager: only their subtree (+ self); admin: PII allowed, filters and grouping work.
        $names = array_column((array) $run($lead, ['dataset' => 'employees', 'columns' => ['full_name']])->assertOk()->json('data.rows'), 'full_name');
        $this->assertEqualsCanonicalizing(['Lead Person', 'Worker Person', 'Peer Person'], $names);
        $run($admin, ['dataset' => 'employees', 'columns' => ['full_name', 'birth_date'], 'filters' => [['column' => 'full_name', 'op' => 'contains', 'value' => 'work']]])
            ->assertOk()->assertJsonPath('data.rows', [['full_name' => 'Worker Person', 'birth_date' => '1990-05-01']]);
        $run($admin, ['dataset' => 'employees', 'columns' => ['full_name'], 'filters' => [['column' => 'birth_date', 'op' => 'gte', 'value' => '1990-05-01']]])
            ->assertOk()->assertJsonCount(1, 'data.rows');
        $grouped = $run($admin, ['dataset' => 'employees', 'group_by' => 'status', 'aggregate' => ['fn' => 'count']])->assertOk()->json('data');
        $this->assertSame(['status', 'value'], $grouped['columns']);
        $this->assertEquals([['status' => 'active', 'value' => 5]], $grouped['rows']);

        $datasets = array_column((array) $this->actingAs($lead)->getJson('/api/reports/builder/datasets')->assertOk()->json('data'), 'columns', 'key');
        $this->assertNotContains('birth_date', array_column($datasets['employees'], 'key'));
        $this->assertArrayNotHasKey('assets', $datasets);
    }

    public function test_csv_export_is_streamed_and_injection_safe(): void
    {
        $admin = $this->login(UserRole::Admin);
        $this->employee(['full_name' => '=HYPERLINK("http://example.test","click")']);
        $this->employee(['full_name' => '+SUM(A1:A2)']);

        $response = $this->actingAs($admin)->post('/api/reports/builder/csv', ['dataset' => 'employees', 'columns' => ['full_name', 'id']], ['Accept' => 'application/json'])->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="employees-', (string) $response->headers->get('Content-Disposition'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFfull_name,id\n", $csv);
        $this->assertStringContainsString("'+SUM(A1:A2)", $csv);
        $this->assertStringContainsString("\"'=HYPERLINK(\"\"http://example.test\"\",\"\"click\"\")\"", $csv);
        $this->assertStringNotContainsString("\n=HYPERLINK", $csv);

        $catalogCsv = $this->actingAs($admin)->get('/api/reports/catalog/headcount/csv')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFbranch,department,headcount\n", $catalogCsv);
    }

    public function test_saved_reports_are_private_and_rerun_under_the_current_scope(): void
    {
        $admin = $this->login(UserRole::Admin);
        $other = $this->login(UserRole::Admin);
        $this->employee(['full_name' => 'Saved Person']);

        $this->actingAs($admin)->postJson('/api/reports/saved', ['name' => 'Bad', 'kind' => 'builder', 'definition' => ['dataset' => 'employees', 'columns' => ['nope']]])
            ->assertUnprocessable();
        $id = $this->actingAs($admin)->postJson('/api/reports/saved', ['name' => 'People list', 'kind' => 'builder', 'definition' => ['dataset' => 'employees', 'columns' => ['full_name']]])
            ->assertCreated()->json('data.id');
        $catalog = $this->actingAs($admin)->postJson('/api/reports/saved', ['name' => 'Heads', 'kind' => 'catalog', 'definition' => ['key' => 'headcount', 'filters' => ['to' => '2030-01-01', 'evil' => 'x']]])
            ->assertCreated()->assertJsonPath('data.definition', ['key' => 'headcount', 'filters' => ['to' => '2030-01-01']])->json('data.id');

        $this->actingAs($admin)->getJson("/api/reports/saved/$id/run")->assertOk()->assertJsonPath('data.rows.0.full_name', 'Saved Person');
        $this->actingAs($admin)->getJson("/api/reports/saved/$catalog/run")->assertOk()->assertJsonPath('data.rows.0.headcount', 1);
        $this->assertStringContainsString('Saved Person', $this->actingAs($admin)->get("/api/reports/saved/$id/run?format=csv")->assertOk()->streamedContent());
        $this->actingAs($other)->getJson('/api/reports/saved')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other)->getJson("/api/reports/saved/$id/run")->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/reports/saved/$id")->assertNotFound();
        $this->actingAs($admin)->putJson("/api/reports/saved/$id", ['name' => 'Renamed', 'kind' => 'builder', 'definition' => ['dataset' => 'employees', 'columns' => ['id']]])
            ->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->actingAs($admin)->deleteJson("/api/reports/saved/$id")->assertNoContent();
    }

    public function test_enps_trend_uses_closed_waves_and_respects_suppression(): void
    {
        $admin = $this->login(UserRole::Admin);
        $people = $this->people(5);
        $big = $this->wave($this->survey(['title' => 'Big wave']));
        foreach ($people as $i => $p) {
            $this->answer($big, $p, ['enps' => $i < 3 ? 10 : 5, 'q1' => 4]);
        }
        $small = $this->wave($this->survey(['title' => 'Small wave']));
        $this->answer($small, $people[0], ['enps' => 9, 'q1' => 4]);
        $open = $this->wave($this->survey(['title' => 'Open wave']));
        $this->answer($open, $people[1], ['enps' => 9, 'q1' => 4]);
        SurveyWave::query()->whereKey([$big->id, $small->id])->update(['status' => 'closed', 'salt' => null]);

        $rows = (array) $this->actingAs($admin)->getJson('/api/reports/catalog/enps_trend')->assertOk()->json('data.rows');
        $bySurvey = array_column($rows, null, 'survey');
        $this->assertArrayNotHasKey('Open wave', $bySurvey, 'only closed waves');
        $this->assertSame(['responses' => 5, 'enps' => 20], array_intersect_key($bySurvey['Big wave'], ['responses' => 1, 'enps' => 1]));
        $this->assertNull($bySurvey['Small wave']['enps'], 'fewer than the minimum group → suppressed');
        $this->assertNull($bySurvey['Small wave']['responses']);
    }
}
