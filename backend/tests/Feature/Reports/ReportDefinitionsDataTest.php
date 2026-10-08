<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\People\Models\Employee;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\PeopleFixtures;
use Tests\Support\RecruitingFixtures;
use Tests\Support\ScriptFixtures;
use Tests\TestCase;

/**
 * Catalog reports and builder datasets on seeded (synthetic) data: the row-mapping branches compute concrete values —
 * time to hire, OKR progress, desk SLA breaches, touches per recruiter, script scores, review completion, sources,
 * leave usage/balances, assets by status and the builder's filters/aggregates.
 */
final class ReportDefinitionsDataTest extends TestCase
{
    use PeopleFixtures, RecruitingFixtures, RefreshDatabase, ScriptFixtures;

    private const string NOW = '2026-10-05 10:00:00';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Rows of a catalog report indexed by the given key column(s).
     *
     * @param  list<string>  $by
     * @return array<string, array<string, mixed>>
     */
    private function catalogRows(User $user, string $url, array $by): array
    {
        $out = [];
        foreach ((array) $this->actingAs($user)->getJson($url)->assertOk()->json('data.rows') as $row) {
            $out[implode('|', array_map(static fn (string $k): string => (string) $row[$k], $by))] = $row;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return TestResponse<JsonResponse>
     */
    private function build(User $user, array $spec): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/reports/builder/run', $spec)->assertOk();
    }

    /** Hired application with explicit dates (created → closed). */
    private function hired(Vacancy $vacancy, string $createdAt, string $closedAt): void
    {
        $application = $this->applied($vacancy);
        DB::table('applications')->where('id', $application->id)->update(['status' => 'hired', 'created_at' => $createdAt, 'closed_at' => $closedAt]);
    }

    public function test_time_to_hire_counts_days_per_vacancy(): void
    {
        Carbon::setTestNow(self::NOW);
        $admin = $this->login(UserRole::Admin);
        $alpha = Vacancy::factory()->create(['title' => 'Alpha Developer']);
        $beta = Vacancy::factory()->create(['title' => 'Beta Tester']);
        $this->hired($alpha, '2026-09-01 08:00:00', '2026-09-11 17:00:00'); // 10 days
        $this->hired($alpha, '2026-09-05 08:00:00', '2026-09-25 09:00:00'); // 20 days
        $this->hired($alpha, '2026-09-10 08:00:00', '2026-09-13 12:00:00'); // 3 days
        $this->hired($alpha, '2026-07-01 08:00:00', '2026-08-15 12:00:00'); // closed before the range
        $this->hired($beta, '2026-09-02 08:00:00', '2026-09-06 08:00:00'); // 4 days
        $this->hired($beta, '2026-09-03 08:00:00', '2026-09-10 08:00:00'); // 7 days
        $this->applied($beta); // active, not a hire

        $response = $this->actingAs($admin)->getJson('/api/reports/catalog/time_to_hire?from=2026-09-01&to=2026-09-30')->assertOk();
        $rows = array_column((array) $response->json('data.rows'), null, 'vacancy');

        $this->assertEquals(['vacancy' => 'Alpha Developer', 'hires' => 3, 'avg_days' => 11, 'median_days' => 10], $rows['Alpha Developer']);
        $this->assertEquals(['vacancy' => 'Beta Tester', 'hires' => 2, 'avg_days' => 5.5, 'median_days' => 5.5], $rows['Beta Tester']);
        $this->assertEquals(5, $response->json('data.totals.hires'));
        $this->assertEquals(8.8, $response->json('data.totals.avg_days'), '(11*3 + 5.5*2) / 5');
    }

    public function test_okr_progress_by_scope_for_a_period(): void
    {
        Carbon::setTestNow(self::NOW);
        $admin = $this->login(UserRole::Admin);
        $owner = $this->employee(['full_name' => 'Okr Owner']);
        $objective = function (string $scope, int $progress, string $status, string $period = '2026-Q4') use ($owner): void {
            DB::table('objectives')->insert([
                'scope' => $scope,
                'owner_employee_id' => in_array($scope, ['personal', 'team'], true) ? $owner->id : null,
                'period' => $period,
                'title' => "Objective $scope $progress",
                'key_results' => '[]',
                'progress' => $progress,
                'status' => $status,
                'visibility' => 'public',
            ]);
        };
        $objective('personal', 40, 'active');
        $objective('personal', 100, 'achieved');
        $objective('team', 50, 'active');
        $objective('team', 60, 'active');
        $objective('team', 71, 'missed');
        $objective('company', 25, 'active');
        $objective('company', 90, 'achieved', '2026-Q3'); // another period

        $rows = $this->catalogRows($admin, '/api/reports/catalog/okr_progress?period=2026-Q4', ['scope']);

        $this->assertSame(['company', 'personal', 'team'], array_keys($rows), 'sorted by scope');
        $this->assertEquals(['scope' => 'company', 'objectives' => 1, 'avg_progress' => 25, 'achieved' => 0], $rows['company']);
        $this->assertEquals(['scope' => 'personal', 'objectives' => 2, 'avg_progress' => 70, 'achieved' => 1], $rows['personal']);
        $this->assertEquals(['scope' => 'team', 'objectives' => 3, 'avg_progress' => 60.3, 'achieved' => 0], $rows['team']);
    }

    public function test_desk_sla_counts_breaches_per_category(): void
    {
        Carbon::setTestNow(self::NOW);
        $admin = $this->login(UserRole::Admin);
        $employee = $this->employee(['full_name' => 'Desk Requester']);
        $access = DB::table('desk_categories')->insertGetId(['name' => 'Access', 'first_response_hours' => 4, 'resolve_hours' => 24, 'active' => true]);
        $payroll = DB::table('desk_categories')->insertGetId(['name' => 'Payroll', 'first_response_hours' => null, 'resolve_hours' => null, 'active' => true]);
        $case = function (int $category, string $status, string $createdAt, ?string $firstResponseAt, ?string $resolvedAt) use ($employee): void {
            DB::table('desk_cases')->insert([
                'employee_id' => $employee->id,
                'category_id' => $category,
                'subject' => 'Synthetic case',
                'body' => 'Synthetic body',
                'status' => $status,
                'first_response_at' => $firstResponseAt,
                'resolved_at' => $resolvedAt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        };
        // Within SLA: replied after 1h, resolved after 11h.
        $case($access, 'resolved', '2026-10-01 09:00:00', '2026-10-01 10:00:00', '2026-10-01 20:00:00');
        // Replied after 6h (> 4h) and still open past the 24h resolve target.
        $case($access, 'in_progress', '2026-10-02 09:00:00', '2026-10-02 15:00:00', null);
        // A category without SLA targets is never breached.
        $case($payroll, 'new', '2026-10-04 12:00:00', null, null);
        // Opened before the range.
        $case($access, 'new', '2026-09-20 09:00:00', null, null);

        $rows = $this->catalogRows($admin, '/api/reports/catalog/desk_sla?from=2026-10-01&to=2026-10-05', ['category']);

        $this->assertSame(['Access', 'Payroll'], array_keys($rows));
        $this->assertEquals(['category' => 'Access', 'cases' => 2, 'open' => 1, 'first_response_breached' => 1, 'resolve_breached' => 1, 'breached_pct' => 50], $rows['Access']);
        $this->assertEquals(['category' => 'Payroll', 'cases' => 1, 'open' => 1, 'first_response_breached' => 0, 'resolve_breached' => 0, 'breached_pct' => 0], $rows['Payroll']);
    }

    public function test_recruiter_touches_split_by_channel_and_product(): void
    {
        Carbon::setTestNow(self::NOW);
        $admin = $this->login(UserRole::Admin);
        $rita = User::factory()->create(['name' => 'Rita Recruiter']);
        $touch = static function (?int $authorId, string $channel, bool $viaProduct, string $at): void {
            DB::table('touchpoints')->insert([
                'channel' => $channel,
                'direction' => 'out',
                'author_id' => $authorId,
                'occurred_at' => $at,
                'body' => 'Synthetic message',
                'via_product' => $viaProduct,
            ]);
        };
        $touch($rita->id, 'call', false, '2026-10-02 09:00:00');
        $touch($rita->id, 'call', false, '2026-10-03 09:00:00');
        $touch($rita->id, 'call', true, '2026-10-03 10:00:00');
        $touch($rita->id, 'telegram', true, '2026-10-04 10:00:00');
        $touch(null, 'email', false, '2026-10-04 11:00:00');
        $touch($rita->id, 'system', false, '2026-10-04 12:00:00'); // system touches are not contacts
        $touch($rita->id, 'call', false, '2026-09-01 09:00:00'); // before the range

        $response = $this->actingAs($admin)->getJson('/api/reports/catalog/recruiter_touches?from=2026-10-01&to=2026-10-05')->assertOk();
        $rows = [];
        foreach ((array) $response->json('data.rows') as $row) {
            $rows[$row['recruiter'].'|'.$row['channel']] = $row;
        }

        $this->assertEqualsCanonicalizing(['Rita Recruiter|call', 'Rita Recruiter|telegram', '—|email'], array_keys($rows));
        $this->assertEquals(['recruiter' => 'Rita Recruiter', 'channel' => 'call', 'touches' => 3, 'via_product' => 1], $rows['Rita Recruiter|call']);
        $this->assertEquals(['recruiter' => 'Rita Recruiter', 'channel' => 'telegram', 'touches' => 1, 'via_product' => 1], $rows['Rita Recruiter|telegram']);
        $this->assertEquals(['recruiter' => '—', 'channel' => 'email', 'touches' => 1, 'via_product' => 0], $rows['—|email']);
        $this->assertEquals(5, $response->json('data.totals.touches'));
        $this->assertEquals(2, $response->json('data.totals.via_product'));

        // Builder over the same touchpoints (no date filter): count per channel, system included.
        $grouped = $this->build($admin, ['dataset' => 'touchpoints', 'group_by' => 'channel', 'aggregate' => ['fn' => 'count']])->json('data.rows');
        $this->assertEquals(['call' => 4, 'email' => 1, 'system' => 1, 'telegram' => 1], array_column((array) $grouped, 'value', 'channel'));
    }

    /**
     * Report days are Kyiv days: "today" of headcount at 00:30 Kyiv (21:30 UTC) is the new day, and a range day covers
     * 00:00â€“24:00 Kyiv (21:00 UTC of the day before â€“ 21:00 UTC), not the UTC day.
     */
    public function test_report_days_are_kyiv_days(): void
    {
        Carbon::setTestNow('2026-10-11 21:30:00'); // 2026-10-12 00:30 Kyiv
        $admin = $this->login(UserRole::Admin);
        $this->employee(['full_name' => 'Starts Today', 'hired_at' => '2026-10-12']);
        $headcount = (int) array_sum(array_column((array) $this->actingAs($admin)->getJson('/api/reports/catalog/headcount')->assertOk()->json('data.rows'), 'headcount'));
        Carbon::setTestNow('2026-10-11 20:30:00'); // 23:30 Kyiv on Oct 11: not hired yet
        $before = (int) array_sum(array_column((array) $this->actingAs($admin)->getJson('/api/reports/catalog/headcount')->assertOk()->json('data.rows'), 'headcount'));
        $this->assertSame($before + 1, $headcount);

        $rita = User::factory()->create(['name' => 'Rita Recruiter']);
        foreach (['2026-10-11 20:59:59', '2026-10-11 21:00:00', '2026-10-12 20:59:59', '2026-10-12 21:00:00'] as $at) {
            DB::table('touchpoints')->insert(['channel' => 'call', 'direction' => 'out', 'author_id' => $rita->id, 'occurred_at' => $at, 'body' => 'Synthetic', 'via_product' => false]);
        }
        $response = $this->actingAs($admin)->getJson('/api/reports/catalog/recruiter_touches?from=2026-10-12&to=2026-10-12')->assertOk();
        $this->assertEquals(2, $response->json('data.totals.touches'), 'Oct 12 Kyiv = 2026-10-11 21:00 .. 2026-10-12 20:59:59 UTC');
    }

    public function test_script_scores_per_recruiter(): void
    {
        Carbon::setTestNow(self::NOW);
        $admin = $this->login(UserRole::Admin);
        $versionId = (int) $this->publishedScript()->active_version_id;
        $rita = User::factory()->create(['name' => 'Rita Recruiter']);
        $bob = User::factory()->create(['name' => 'Bob Recruiter']);
        $evaluate = static function (int $authorId, int $score, bool $fixed, string $at) use ($versionId): void {
            $touchpoint = DB::table('touchpoints')->insertGetId([
                'channel' => 'call',
                'direction' => 'out',
                'author_id' => $authorId,
                'occurred_at' => $at,
                'body' => 'Synthetic call',
                'via_product' => false,
            ]);
            DB::table('script_evaluations')->insert([
                'touchpoint_id' => $touchpoint,
                'script_version_id' => $versionId,
                'engine' => 'rules',
                'score' => $score,
                'result' => json_encode(['steps' => [], 'next_step' => ['fixed' => $fixed]]),
                'created_at' => $at,
            ]);
        };
        $evaluate($rita->id, 80, true, '2026-10-02 09:00:00');
        $evaluate($rita->id, 60, false, '2026-10-03 09:00:00');
        $evaluate($bob->id, 90, true, '2026-10-04 09:00:00');
        $evaluate($bob->id, 10, false, '2026-09-01 09:00:00'); // before the range

        $rows = $this->catalogRows($admin, '/api/reports/catalog/script_scores?from=2026-10-01&to=2026-10-05', ['recruiter']);

        $this->assertEqualsCanonicalizing(['Rita Recruiter', 'Bob Recruiter'], array_keys($rows));
        $this->assertEquals(['recruiter' => 'Rita Recruiter', 'evaluations' => 2, 'avg_score' => 70, 'next_step_fixed_pct' => 50], $rows['Rita Recruiter']);
        $this->assertEquals(['recruiter' => 'Bob Recruiter', 'evaluations' => 1, 'avg_score' => 90, 'next_step_fixed_pct' => 100], $rows['Bob Recruiter']);
    }

    public function test_review_completion_per_active_or_closed_cycle(): void
    {
        Carbon::setTestNow(self::NOW);
        $admin = $this->login(UserRole::Admin);
        $people = [$this->employee(), $this->employee(), $this->employee(), $this->employee()];
        $cycle = static fn (string $name, string $status): int => DB::table('review_cycles')->insertGetId([
            'name' => $name,
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
            'participants' => json_encode(['branch_ids' => [], 'department_ids' => []]),
            'types' => json_encode(['self', 'manager']),
            'competency_ids' => '[]',
            'anonymous' => true,
            'deadlines' => '{}',
            'status' => $status,
        ]);
        /** @param list<string> $statuses */
        $assign = static function (int $cycleId, array $statuses) use ($people): void {
            foreach ($statuses as $i => $status) {
                DB::table('review_assignments')->insert([
                    'cycle_id' => $cycleId,
                    'subject_employee_id' => $people[$i]->id,
                    'reviewer_employee_id' => $people[($i + 1) % 4]->id,
                    'type' => 'manager',
                    'status' => $status,
                    'submitted_at' => $status === 'submitted' ? '2026-07-01 10:00:00' : null,
                ]);
            }
        };
        $assign($cycle('Spring review', 'closed'), ['submitted', 'pending', 'submitted', 'pending']);
        $assign($cycle('Autumn review', 'active'), ['submitted', 'submitted', 'submitted']);
        $assign($cycle('Draft review', 'draft'), ['pending']);

        $rows = $this->catalogRows($admin, '/api/reports/catalog/review_completion', ['cycle']);

        $this->assertSame(['Autumn review', 'Spring review'], array_keys($rows), 'newest cycle first, drafts excluded');
        $this->assertEquals(['cycle' => 'Autumn review', 'status' => 'active', 'assigned' => 3, 'submitted' => 3, 'completion_pct' => 100], $rows['Autumn review']);
        $this->assertEquals(['cycle' => 'Spring review', 'status' => 'closed', 'assigned' => 4, 'submitted' => 2, 'completion_pct' => 50], $rows['Spring review']);
    }

    public function test_source_effectiveness_hire_rate(): void
    {
        Carbon::setTestNow(self::NOW);
        $admin = $this->login(UserRole::Admin);
        $vacancy = Vacancy::factory()->create();
        $hire = $this->applied($vacancy, ['source' => 'djinni']);
        DB::table('applications')->where('id', $hire->id)->update(['status' => 'hired', 'closed_at' => '2026-10-04 10:00:00']);
        $this->applied($vacancy, ['source' => 'djinni']);
        $this->applied($vacancy, ['source' => 'referral']);
        $old = Candidate::factory()->create(['source' => 'linkedin']);
        DB::table('candidates')->where('id', $old->id)->update(['created_at' => '2026-08-01 10:00:00']); // before the range

        $rows = $this->catalogRows($admin, '/api/reports/catalog/source_effectiveness?from=2026-10-01&to=2026-10-05', ['source']);

        $this->assertEqualsCanonicalizing(['djinni', 'referral'], array_keys($rows));
        $this->assertEquals(['source' => 'djinni', 'candidates' => 2, 'hired' => 1, 'hire_rate_pct' => 50], $rows['djinni']);
        $this->assertEquals(['source' => 'referral', 'candidates' => 1, 'hired' => 0, 'hire_rate_pct' => 0], $rows['referral']);
    }

    public function test_leave_usage_balances_and_leave_dataset(): void
    {
        Carbon::setTestNow(self::NOW);
        $admin = $this->login(UserRole::Admin);
        $alice = $this->employee(['full_name' => 'Alice Example']);
        $bob = $this->employee(['full_name' => 'Bob Example']);
        $gone = $this->employee(['full_name' => 'Gone Example', 'status' => 'terminated', 'fired_at' => '2026-06-30']);
        $vacation = (int) DB::table('leave_types')->where('code', 'vacation')->value('id');
        $sick = (int) DB::table('leave_types')->where('code', 'sick')->value('id');
        $request = static function (Employee $e, int $type, string $from, string $to, float $days, string $status = 'approved'): void {
            DB::table('leave_requests')->insert(['employee_id' => $e->id, 'leave_type_id' => $type, 'starts_on' => $from, 'ends_on' => $to, 'days' => $days, 'status' => $status]);
        };
        $request($alice, $vacation, '2026-09-01', '2026-09-05', 5);
        $request($alice, $vacation, '2026-09-21', '2026-09-22', 2);
        $request($bob, $vacation, '2026-08-28', '2026-09-02', 4); // overlaps the range start
        $request($bob, $sick, '2026-09-10', '2026-09-11', 1.5);
        $request($alice, $sick, '2026-09-15', '2026-09-15', 1, 'pending'); // not approved
        $request($bob, $vacation, '2026-10-12', '2026-10-13', 2); // after the range

        $usage = $this->catalogRows($admin, '/api/reports/catalog/leave_usage?from=2026-09-01&to=2026-09-30', ['leave_type']);
        $this->assertSame(['Sick leave', 'Vacation'], array_keys($usage));
        $this->assertEquals(['leave_type' => 'Vacation', 'requests' => 3, 'days' => 11, 'employees' => 2], $usage['Vacation']);
        $this->assertEquals(['leave_type' => 'Sick leave', 'requests' => 1, 'days' => 1.5, 'employees' => 1], $usage['Sick leave']);

        $ledger = static function (Employee $e, int $type, float $delta, string $reason): void {
            DB::table('leave_balance_ledger')->insert(['employee_id' => $e->id, 'leave_type_id' => $type, 'delta' => $delta, 'reason' => $reason]);
        };
        $ledger($alice, $vacation, 24, 'accrual');
        $ledger($alice, $vacation, -5, 'request');
        $ledger($alice, $sick, 3, 'adjustment'); // sick leave does not track a balance
        $ledger($bob, $vacation, 10, 'adjustment');
        $ledger($gone, $vacation, 7, 'adjustment'); // fired: not listed

        $balances = $this->actingAs($admin)->getJson('/api/reports/catalog/leave_balances')->assertOk()->json('data.rows');
        $this->assertEquals([
            ['employee' => 'Alice Example', 'leave_type' => 'Vacation', 'balance' => 19],
            ['employee' => 'Bob Example', 'leave_type' => 'Vacation', 'balance' => 10],
        ], $balances, 'ordered by employee name');

        // Builder: approved days per type starting in September or later.
        $grouped = $this->build($admin, [
            'dataset' => 'leave_requests',
            'group_by' => 'leave_type',
            'aggregate' => ['fn' => 'sum', 'column' => 'days'],
            'filters' => [['column' => 'status', 'op' => 'eq', 'value' => 'approved'], ['column' => 'starts_on', 'op' => 'gte', 'value' => '2026-09-01']],
        ])->json('data');
        $this->assertSame(['leave_type', 'value'], $grouped['columns']);
        $this->assertEquals(['Sick leave' => 1.5, 'Vacation' => 9], array_column((array) $grouped['rows'], 'value', 'leave_type'));
        $this->assertEquals(10.5, $grouped['totals']['value']);
    }

    public function test_assets_by_status_and_assets_dataset(): void
    {
        Carbon::setTestNow(self::NOW);
        $admin = $this->login(UserRole::Admin);
        $holder = $this->employee(['full_name' => 'Holder Person']);
        $laptop = DB::table('asset_types')->insertGetId(['name' => 'Laptop']);
        $monitor = DB::table('asset_types')->insertGetId(['name' => 'Monitor']);
        $asset = static function (string $inv, string $name, ?int $type, string $status, ?string $cost, ?string $purchasedAt, ?int $employeeId = null): void {
            DB::table('assets')->insert(['inventory_number' => $inv, 'name' => $name, 'type_id' => $type, 'status' => $status, 'cost' => $cost, 'purchased_at' => $purchasedAt, 'employee_id' => $employeeId]);
        };
        $asset('INV-001', 'Laptop One', $laptop, 'assigned', '1000.50', '2026-03-10', $holder->id);
        $asset('INV-002', 'Laptop Two', $laptop, 'assigned', '999.50', '2025-11-01', $holder->id);
        $asset('INV-003', 'Monitor One', $monitor, 'in_stock', '200.00', '2026-07-01');
        $asset('INV-004', 'Cable Box', null, 'in_stock', null, null);

        $rows = $this->catalogRows($admin, '/api/reports/catalog/assets_by_status', ['status', 'type']);
        $this->assertEqualsCanonicalizing(['assigned|Laptop', 'in_stock|Monitor', 'in_stock|—'], array_keys($rows));
        $this->assertEquals(['status' => 'assigned', 'type' => 'Laptop', 'assets' => 2, 'cost' => 2000], $rows['assigned|Laptop']);
        $this->assertEquals(['status' => 'in_stock', 'type' => 'Monitor', 'assets' => 1, 'cost' => 200], $rows['in_stock|Monitor']);
        $this->assertEquals(['status' => 'in_stock', 'type' => '—', 'assets' => 1, 'cost' => 0], $rows['in_stock|—'], 'no type, no cost');

        // Raw rows: every column cast by its type, number filter, cost totals.
        $raw = $this->build($admin, [
            'dataset' => 'assets',
            'columns' => ['inventory_number', 'name', 'serial', 'type', 'status', 'cost', 'purchased_at', 'holder'],
            'filters' => [['column' => 'cost', 'op' => 'gt', 'value' => 500]],
        ])->json('data');
        $this->assertEquals([
            ['inventory_number' => 'INV-001', 'name' => 'Laptop One', 'serial' => null, 'type' => 'Laptop', 'status' => 'assigned', 'cost' => 1000.5, 'purchased_at' => '2026-03-10', 'holder' => 'Holder Person'],
            ['inventory_number' => 'INV-002', 'name' => 'Laptop Two', 'serial' => null, 'type' => 'Laptop', 'status' => 'assigned', 'cost' => 999.5, 'purchased_at' => '2025-11-01', 'holder' => 'Holder Person'],
        ], $raw['rows']);
        $this->assertEquals(2000, $raw['totals']['cost']);

        // Each operator on date, number, string and empty values (ordered by inventory number).
        $inv = fn (string $column, string $op, string|int|float|null $value): array => array_column((array) $this->build($admin, [
            'dataset' => 'assets', 'columns' => ['inventory_number'], 'filters' => [['column' => $column, 'op' => $op, 'value' => $value]],
        ])->json('data.rows'), 'inventory_number');
        $this->assertSame(['INV-001'], $inv('purchased_at', 'eq', '2026-03-10'));
        $this->assertSame(['INV-002', 'INV-003'], $inv('purchased_at', 'neq', '2026-03-10'));
        $this->assertSame(['INV-003'], $inv('purchased_at', 'gt', '2026-03-10'));
        $this->assertSame(['INV-001', 'INV-003'], $inv('purchased_at', 'gte', '2026-03-10'));
        $this->assertSame(['INV-002'], $inv('purchased_at', 'lt', '2026-01-01'));
        $this->assertSame(['INV-001', 'INV-002'], $inv('purchased_at', 'lte', '2026-03-10'));
        $this->assertSame(['INV-003'], $inv('cost', 'eq', '200'));
        $this->assertSame(['INV-001', 'INV-002'], $inv('cost', 'gte', 999.5));
        $this->assertSame(['INV-003'], $inv('cost', 'lt', 500));
        $this->assertSame(['INV-003', 'INV-004'], $inv('status', 'neq', 'assigned'));
        $this->assertSame(['INV-004'], $inv('type', 'eq', null));
        $this->assertSame(['INV-001', 'INV-002', 'INV-003'], $inv('type', 'neq', null));
        $this->assertSame(['INV-003'], $inv('name', 'contains', 'monitor'));

        // Grouping: sum adds up into a total, an average does not.
        $sum = $this->build($admin, ['dataset' => 'assets', 'group_by' => 'status', 'aggregate' => ['fn' => 'sum', 'column' => 'cost']])->json('data');
        $this->assertEquals(['assigned' => 2000, 'in_stock' => 200], array_column((array) $sum['rows'], 'value', 'status'));
        $this->assertEquals(2200, $sum['totals']['value']);
        $avg = $this->build($admin, ['dataset' => 'assets', 'group_by' => 'status', 'aggregate' => ['fn' => 'avg', 'column' => 'cost']])->json('data');
        $this->assertEquals(['assigned' => 1000, 'in_stock' => 200], array_column((array) $avg['rows'], 'value', 'status'));
        $this->assertNull($avg['totals']['value']);
    }
}
