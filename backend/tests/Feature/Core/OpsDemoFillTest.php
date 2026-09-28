<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Services\Demo\DemoDataService;
use App\Modules\Directory\Models\Branch;
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
        $this->assertSame(0, DB::table('demo_records')->count());
    }

    public function test_fill_is_idempotent_and_reset_removes_only_demo_rows(): void
    {
        $realUser = User::query()->create(['email' => 'real@example.com', 'name' => 'Real', 'status' => 'active']);
        $realBranch = Branch::query()->create(['name' => 'Real branch', 'status' => 'active']);
        $before = $this->tableCounts();

        $first = $this->postJson('/api/ops/demo-fill?confirm=demo', [], self::HEADERS)->assertOk()->json();
        $this->assertFalse($first['already']);
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
        $second = $this->postJson('/api/ops/demo-fill?confirm=demo', [], self::HEADERS)->assertOk()->json();
        $this->assertTrue($second['already']);
        $this->assertSame($afterFill, $this->tableCounts(), 're-run creates nothing');

        $this->postJson('/api/ops/demo-fill?confirm=demo&reset=1', [], self::HEADERS)->assertOk()->assertJsonPath('action', 'reset');
        $after = $this->tableCounts();
        unset($before['audit_log'], $after['audit_log']);
        $this->assertSame($before, $after, 'reset returns every table to its pre-fill size');
        $this->assertNotNull(User::query()->find($realUser->id));
        $this->assertNotNull(Branch::query()->find($realBranch->id));
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
