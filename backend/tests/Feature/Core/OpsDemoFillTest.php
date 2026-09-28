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
        $this->assertSame(60, DB::table('employees')->where('full_name', 'like', DemoDataService::PREFIX.'%')->count());
        $this->assertSame(150, DB::table('candidates')->where('full_name', 'like', DemoDataService::PREFIX.'%')->count());
        $this->assertGreaterThanOrEqual(600, DB::table('touchpoints')->count());
        $this->assertSame(2, DB::table('survey_waves')->where('status', 'closed')->count());
        $this->assertGreaterThan(0, DB::table('survey_wave_members')->count());
        $this->assertNull(DB::table('survey_responses')->whereNotNull('employee_id')->value('id'), 'anonymous waves keep no employee id');
        $perDepartment = DB::table('survey_responses')->groupBy('department_id')->selectRaw('count(*) as c')->pluck('c')->all();
        $this->assertGreaterThanOrEqual(5, min($perDepartment));
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
        $this->assertSame(59, DB::table('employees')->where('full_name', 'like', DemoDataService::PREFIX.'%')->count());
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
