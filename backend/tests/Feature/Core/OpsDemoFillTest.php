<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Services\Demo\DemoDataService;
use App\Modules\Directory\Models\Branch;
use App\Modules\Recruiting\Services\RecruitingDemoData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class OpsDemoFillTest extends TestCase
{
    use RefreshDatabase;

    private const array HEADERS = ['X-Ops-Secret' => 'test-secret'];

    private const int PEOPLE = 128;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ops.secret' => 'test-secret']);
    }

    public function test_guarded_by_secret_and_confirm(): void
    {
        $this->postJson('/api/ops/demo-fill?confirm=demo')->assertUnauthorized();
        $this->postJson('/api/ops/demo-fill', [], self::HEADERS)->assertStatus(422)->assertJsonPath('error', 'confirm_required');
        $this->postJson('/api/ops/demo-fill?confirm=demo', [], self::HEADERS)->assertStatus(422)->assertJsonPath('error', 'step_required');
        $this->postJson('/api/ops/demo-fill?confirm=demo&step=nope', [], self::HEADERS)->assertStatus(422)->assertJsonPath('error', 'unknown_step');
        $this->postJson('/api/ops/demo-fill?confirm=demo&step=people', [], self::HEADERS)->assertStatus(409)->assertJsonPath('error', 'previous_step_missing:org');
        $this->getJson('/api/ops/demo-fill?confirm=demo&steps=list', self::HEADERS)->assertOk()->assertJsonPath('steps', DemoDataService::STEPS)->assertJsonPath('done', []);
        $this->assertSame(0, DB::table('demo_records')->count());
    }

    public function test_fill_is_idempotent_and_reset_removes_only_demo_rows(): void
    {
        $realUser = User::query()->create(['email' => 'real@example.com', 'name' => 'Real', 'status' => 'active']);
        $realBranch = Branch::query()->create(['name' => 'Real branch', 'status' => 'active']);
        $before = $this->tableCounts();

        foreach (DemoDataService::STEPS as $step) {
            $this->postJson('/api/ops/demo-fill?confirm=demo&step='.$step, [], self::HEADERS)->assertOk()->assertJsonPath('already', false);
        }
        $this->getJson('/api/ops/demo-fill?confirm=demo&steps=list', self::HEADERS)->assertJsonPath('done', DemoDataService::STEPS);
        $this->assertSame(self::PEOPLE, DB::table('employees')->where('full_name', 'like', DemoDataService::PREFIX.'%')->count());
        $this->assertSame(150, DB::table('candidates')->where('full_name', 'like', DemoDataService::PREFIX.'%')->count());
        $this->assertGreaterThanOrEqual(600, DB::table('touchpoints')->count());
        $this->assertSame(2, DB::table('survey_waves')->where('status', 'closed')->count());
        $this->assertGreaterThan(0, DB::table('survey_wave_members')->count());
        $this->assertNull(DB::table('survey_responses')->whereNotNull('employee_id')->value('id'), 'anonymous waves keep no employee id');
        // Departments big enough for anonymous breakdowns (≥ 5 answers per wave); the small branch directorates are hidden.
        $perDepartment = DB::table('survey_responses')->groupBy('wave_id', 'department_id')->selectRaw('count(*) as c')->pluck('c')->all();
        $this->assertGreaterThanOrEqual(24, count(array_filter($perDepartment, static fn ($c): bool => (int) $c >= 5)));
        foreach (['female', 'male'] as $gender) {
            $this->assertGreaterThanOrEqual(5, DB::table('employees')->where('gender', $gender)->count());
        }
        $this->assertGreaterThan(0, DB::table('leave_requests')->where('status', 'approved')
            ->where('starts_on', '<=', now()->toDateString())->where('ends_on', '>=', now()->toDateString())->count());
        $this->assertGreaterThan(0, DB::table('timesheets')->where('overtime_hours', '>', 0)->count());
        $this->assertGreaterThan(0, DB::table('script_evaluations')->count());
        $this->assertSame(200, $this->getJson('/api/health')->status());

        $afterFill = $this->tableCounts();
        foreach (DemoDataService::STEPS as $step) {
            $this->postJson('/api/ops/demo-fill?confirm=demo&step='.$step, [], self::HEADERS)->assertOk()->assertJsonPath('already', true);
        }
        $this->assertSame($afterFill, $this->tableCounts(), 're-run creates nothing');

        $this->postJson('/api/ops/demo-fill?confirm=demo&reset=1', [], self::HEADERS)->assertOk()->assertJsonPath('action', 'reset');
        $after = $this->tableCounts();
        unset($before['audit_log'], $after['audit_log']);
        $this->assertSame($before, $after, 'reset returns every table to its pre-fill size');
        $this->assertNotNull(User::query()->find($realUser->id));
        $this->assertNotNull(Branch::query()->find($realBranch->id));
    }

    /** Prod already had rows with the same unique values (earlier preview seed, real people): they are skipped, never touched. */
    public function test_fill_skips_values_taken_by_non_demo_rows(): void
    {
        app(RecruitingDemoData::class)->generate(); // preview seed: demo-N touch ids, *@example.test users
        $real = User::query()->create(['email' => 'demo+emp-01@sinhrm.test', 'name' => 'Real person', 'status' => 'active']);
        $type = DB::table('asset_types')->insertGetId(['name' => DemoDataService::PREFIX.'Ноутбук']);
        DB::table('assets')->insert(['inventory_number' => 'DEMO-0001', 'name' => 'Real laptop', 'status' => 'in_stock', 'type_id' => $type]);
        DB::table('candidates')->insert(['full_name' => 'Real candidate', 'email' => 'candidate501@example.test', 'phone' => '+380679999999', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->tableCounts();

        foreach (DemoDataService::STEPS as $step) {
            $this->postJson('/api/ops/demo-fill?confirm=demo&step='.$step, [], self::HEADERS)->assertOk();
        }
        $this->assertSame(self::PEOPLE - 1, DB::table('employees')->where('full_name', 'like', DemoDataService::PREFIX.'%')->count());
        $this->assertSame('Real person', $real->fresh()?->name);
        $this->assertNull(DB::table('employees')->where('user_id', $real->id)->value('id'));
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $real->id)->count());
        $this->assertSame('Real laptop', DB::table('assets')->where('inventory_number', 'DEMO-0001')->value('name'));
        $this->assertSame(149, DB::table('candidates')->where('full_name', 'like', DemoDataService::PREFIX.'%')->count());
        $this->assertGreaterThanOrEqual(600, DB::table('touchpoints')->where('external_id', 'like', 'demo-fill-%')->count()
            + DB::table('touchpoints')->whereNull('external_id')->whereIn('candidate_id', DB::table('candidates')->where('full_name', 'like', DemoDataService::PREFIX.'%')->pluck('id'))->count());

        $this->postJson('/api/ops/demo-fill?confirm=demo&reset=1', [], self::HEADERS)->assertOk();
        $after = $this->tableCounts();
        unset($before['audit_log'], $after['audit_log']);
        $this->assertSame($before, $after);
    }

    /**
     * Reproduces prod: preview seed + real rows exist, the Scripts module evaluates new call touches between requests
     * (EvaluateTouchpoint), and the whole step sequence runs twice. No errors, no duplicates, reset restores the DB.
     */
    public function test_prod_like_state_full_sequence_twice(): void
    {
        app(RecruitingDemoData::class)->generate();
        User::query()->create(['email' => 'demo+hr-2@sinhrm.test', 'name' => 'Real recruiter', 'status' => 'active']);
        DB::table('candidates')->insert(['full_name' => 'Real candidate', 'email' => 'candidate510@example.test', 'phone' => '+380679999998', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        $script = DB::table('scripts')->insertGetId(['name' => 'Real script', 'channel' => 'call', 'archived' => false, 'created_at' => now(), 'updated_at' => now()]);
        $version = DB::table('script_versions')->insertGetId(['script_id' => $script, 'version' => 1, 'steps' => '[]', 'objections' => '[]', 'templates' => '[]', 'followups' => '[]', 'next_step_patterns' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->tableCounts();

        foreach ([1, 2] as $round) {
            foreach (DemoDataService::STEPS as $step) {
                if ($step === 'scripts' && $round === 1) {
                    // What the EvaluateTouchpoint job did on prod before this step: some demo call touches are evaluated.
                    $calls = DB::table('touchpoints')->join('candidates as c', 'c.id', '=', 'touchpoints.candidate_id')
                        ->where('c.full_name', 'like', DemoDataService::PREFIX.'%')->where('touchpoints.channel', 'call')->limit(3)->pluck('touchpoints.id');
                    $this->assertCount(3, $calls);
                    foreach ($calls as $touchpoint) {
                        DB::table('script_evaluations')->insert(['touchpoint_id' => $touchpoint, 'script_version_id' => $version, 'engine' => 'rules', 'score' => 50, 'result' => '{}', 'created_at' => now()]);
                    }
                }
                $this->postJson('/api/ops/demo-fill?confirm=demo&step='.$step, [], self::HEADERS)->assertOk()->assertJsonPath('already', $round === 2);
            }
        }
        foreach (['email', 'phone'] as $column) {
            $this->assertSame(0, DB::table('candidates')->whereNotNull($column)->groupBy($column)->havingRaw('count(*) > 1')->count($column));
        }
        $this->assertSame(1, DB::table('users')->where('email', 'demo+hr-2@sinhrm.test')->count());
        $this->assertGreaterThan(3, DB::table('script_evaluations')->count());

        $this->postJson('/api/ops/demo-fill?confirm=demo&reset=1', [], self::HEADERS)->assertOk();
        $after = $this->tableCounts();
        unset($before['audit_log'], $after['audit_log']);
        $this->assertSame($before, $after);
    }

    /**
     * Prod has the OLD demo population (flat 3×4 structure, everyone under one director) plus real rows that use it:
     * reset removes every registered demo row, a demo branch still used by a real vacancy is kept, and the next fill
     * builds a realistic org chart: depth ≥ 4, ≤ 9 direct reports, CEO ≤ 7, 120–150 people; a second fill is a no-op.
     */
    public function test_reset_replaces_old_population_with_org_chart(): void
    {
        $old = [];
        foreach (['branches', 'departments', 'positions'] as $table) {
            $old[$table] = DB::table($table)->insertGetId(['name' => DemoDataService::PREFIX.'Старий '.$table, 'status' => 'active']);
        }
        $oldUser = User::query()->create(['email' => 'demo+emp-99@sinhrm.test', 'name' => DemoDataService::PREFIX.'Старий', 'status' => 'active']);
        $oldEmployee = DB::table('employees')->insertGetId(['user_id' => $oldUser->id, 'full_name' => DemoDataService::PREFIX.'Старий', 'status' => 'active',
            'branch_id' => $old['branches'], 'department_id' => $old['departments'], 'position_id' => $old['positions'], 'created_at' => now(), 'updated_at' => now()]);
        foreach ([...$old, 'users' => $oldUser->id, 'employees' => $oldEmployee, 'step:org' => 0, 'step:people' => 0] as $table => $id) {
            DB::table('demo_records')->insert(['table_name' => $table, 'record_id' => $id, 'created_at' => now()]);
        }
        $realUser = User::query()->create(['email' => 'real@example.com', 'name' => 'Real', 'status' => 'active']);
        $real = DB::table('employees')->insertGetId(['user_id' => $realUser->id, 'full_name' => 'Real person', 'status' => 'active',
            'department_id' => $old['departments'], 'manager_id' => $oldEmployee, 'created_at' => now(), 'updated_at' => now()]);
        $vacancy = DB::table('vacancies')->insertGetId(['title' => 'Real vacancy', 'branch_id' => $old['branches'], 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/api/ops/demo-fill?confirm=demo&reset=1', [], self::HEADERS)->assertOk();
        $this->assertSame(0, DB::table('demo_records')->count());
        $this->assertNull(DB::table('employees')->find($oldEmployee));
        $this->assertNull(DB::table('departments')->find($old['departments']));
        $this->assertNull(DB::table('positions')->find($old['positions']));
        $this->assertNotNull(DB::table('branches')->find($old['branches']), 'still used by a real vacancy');
        $this->assertSame($old['branches'], (int) DB::table('vacancies')->where('id', $vacancy)->value('branch_id'));
        $this->assertSame('Real person', DB::table('employees')->where('id', $real)->value('full_name'));

        foreach ([false, true] as $already) {
            foreach (DemoDataService::STEPS as $step) {
                $this->postJson('/api/ops/demo-fill?confirm=demo&step='.$step, [], self::HEADERS)->assertOk()->assertJsonPath('already', $already);
            }
        }
        $employees = DB::table('employees')->where('full_name', 'like', DemoDataService::PREFIX.'%')->get(['id', 'manager_id']);
        $this->assertSame(self::PEOPLE, $employees->count());
        $this->assertGreaterThanOrEqual(120, $employees->count());
        $this->assertLessThanOrEqual(150, $employees->count());
        $roots = $employees->whereNull('manager_id');
        $this->assertCount(1, $roots, 'one CEO');
        $reports = $employees->whereNotNull('manager_id')->countBy('manager_id');
        $this->assertLessThanOrEqual(9, $reports->max());
        $this->assertLessThanOrEqual(7, $reports[$roots->first()->id]);
        $parent = $employees->pluck('manager_id', 'id')->all();
        $depth = 0;
        foreach (array_keys($parent) as $id) {
            for ($d = 1; $parent[$id] !== null; $d++) {
                $id = $parent[$id];
            }
            $depth = max($depth, $d);
        }
        $this->assertGreaterThanOrEqual(4, $depth);
        $this->assertSame(0, DB::table('employees')->where('full_name', 'like', DemoDataService::PREFIX.'%')->whereNull('department_id')->count());
        $this->assertSame(4, DB::table('employees')->join('users', 'users.id', '=', 'employees.user_id')->where('users.email', 'like', 'demo+hr-%')->count());
        // Approvals come from the chain of command, not a single admin.
        $this->assertGreaterThan(5, DB::table('leave_requests')->whereNotNull('approver_id')->distinct()->count('approver_id'));
        $this->assertGreaterThan(5, DB::table('timesheets')->whereNotNull('decided_by')->distinct()->count('decided_by'));
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $out = [];
        foreach (Schema::getTableListing() as $table) {
            $name = (string) preg_replace('/^.*\./', '', $table);
            if (in_array($name, ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs'], true)) {
                continue;
            }
            $out[$name] = DB::table($name)->count();
        }
        ksort($out);

        return $out;
    }
}
