<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Modules\Core\Services\Demo\DemoDataService;
use App\Modules\Core\Services\Demo\DemoName;
use App\Modules\Core\Services\Demo\DemoRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Prod before the switch to one test branch: legacy test rows that demo_records does not know (hand-made «Тестова
 * філія А/Б», vacancy «тест», «Анна Тестенко», old «[ТЕСТ] …» names, a test-domain account) next to real people.
 * Dry run lists them and changes nothing; reset + fill (twice) leaves exactly one test branch, every demo name ends with
 * the marker, and real rows are untouched.
 */
final class DemoLegacyTest extends TestCase
{
    use RefreshDatabase;

    private const array HEADERS = ['X-Ops-Secret' => 'test-secret'];

    /** Every table/column the demo writes a display name into. */
    private const array NAMED = [
        'cities' => 'name', 'departments' => 'name', 'positions' => 'name', 'users' => 'name', 'employees' => 'full_name',
        'vacancies' => 'title', 'candidates' => 'full_name', 'scripts' => 'name', 'rating_scales' => 'name',
        'competencies' => 'name', 'review_cycles' => 'name', 'objectives' => 'title', 'surveys' => 'title',
        'desk_categories' => 'name', 'desk_cases' => 'subject', 'kb_categories' => 'name', 'kb_articles' => 'title',
        'asset_types' => 'name', 'assets' => 'name', 'hiring_requests' => 'title', 'acquisition_channel_costs' => 'note',
    ];

    /** @var array<string, int> */
    private array $real = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['ops.secret' => 'test-secret']);
    }

    public function test_dry_run_lists_legacy_rows_and_changes_nothing(): void
    {
        $this->seedLegacy();
        $before = $this->tableCounts();

        $this->getJson('/api/ops/demo-fill?confirm=demo&reset=1&dry=1', self::HEADERS)->assertOk()
            ->assertJsonPath('action', 'reset-dry-run')
            ->assertJsonPath('legacy', ['branches' => 2, 'departments' => 1, 'users' => 1, 'employees' => 2, 'vacancies' => 1, 'candidates' => 1])
            ->assertJsonPath('effects.real_user_links_to_test_branches', 1)
            ->assertJsonPath('effects.other_employees_in_test_branches', 0)
            ->assertJsonPath('effects.branches_kept_in_use', 0);
        $this->assertSame($before, $this->tableCounts());
        $this->assertSame(0, DB::table('demo_records')->count());
    }

    public function test_reset_then_fill_twice_gives_one_branch_suffix_names_and_keeps_real_rows(): void
    {
        $this->seedLegacy();
        $realBefore = $this->realSnapshot();

        $counts = [];
        foreach ([1, 2] as $round) {
            $this->postJson('/api/ops/demo-fill?confirm=demo&reset=1', [], self::HEADERS)->assertOk();
            foreach ([false, true] as $already) {
                foreach (DemoDataService::STEPS as $step) {
                    $this->postJson('/api/ops/demo-fill?confirm=demo&step='.$step, [], self::HEADERS)->assertOk()->assertJsonPath('already', $already);
                }
            }
            $counts[$round] = $this->tableCounts();
        }
        unset($counts[1]['audit_log'], $counts[2]['audit_log']);
        $this->assertSame($counts[1], $counts[2], 'reset + fill gives the same state every time');

        // Exactly one test branch; the legacy ones are gone.
        $registry = app(DemoRegistry::class);
        $branches = $registry->ids('branches');
        $this->assertCount(1, $branches);
        $this->assertSame(DemoName::BRANCH, DB::table('branches')->where('id', $branches[0])->value('name'));
        $this->assertSame(0, DB::table('branches')->whereIn('name', ['Тестова філія А', 'Тестова філія Б'])->orWhere('name', 'like', '[ТЕСТ]%')->count());
        $this->assertSame([$this->real['branch'], $branches[0]], array_map('intval', DB::table('branches')->orderBy('id')->pluck('id')->all()));
        foreach (['employees', 'vacancies', 'hiring_requests'] as $table) {
            $this->assertSame([$branches[0]], array_map('intval', DB::table($table)->whereIn('id', $registry->ids($table))->distinct()->pluck('branch_id')->all()), $table);
        }
        $this->assertSame([$branches[0]], array_map('intval', DB::table('branch_user')->whereIn('user_id', $registry->ids('users'))->distinct()->pluck('branch_id')->all()));
        $this->assertSame(0, DB::table('vacancies')->where('title', 'тест')->count());
        $this->assertSame(0, DB::table('employees')->where('full_name', 'Анна Тестенко')->count());
        $this->assertSame(0, DB::table('users')->where('email', 'demo+old@sinhrm.test')->count());

        // The marker sits at the end of every demo name, never at the start (org chart departments keep their cities).
        foreach (self::NAMED as $table => $column) {
            $names = DB::table($table)->whereIn('id', $registry->ids($table))->pluck($column)->all();
            $this->assertNotEmpty($names, $table);
            foreach ($names as $name) {
                $this->assertStringEndsWith(DemoName::SUFFIX, (string) $name, $table);
            }
            $this->assertSame(0, DB::table($table)->where($column, 'like', '[ТЕСТ]%')->count(), $table.': no name starts with the marker');
        }
        $this->assertSame(128, DB::table('employees')->where('full_name', 'like', '%'.DemoName::SUFFIX)->count());
        $this->assertSame(1, DB::table('departments')->where('name', 'Навчальний відділ Дніпро'.DemoName::SUFFIX)->count());

        $this->assertSame($realBefore, $this->realSnapshot(), 'real rows and real users\' branch links are untouched');
    }

    private function seedLegacy(): void
    {
        $now = now();
        $a = DB::table('branches')->insertGetId(['name' => 'Тестова філія А', 'status' => 'active']);
        $b = DB::table('branches')->insertGetId(['name' => 'Тестова філія Б', 'status' => 'active']);
        $this->real['branch'] = DB::table('branches')->insertGetId(['name' => 'Реальна філія', 'status' => 'active']);
        $oldDepartment = DB::table('departments')->insertGetId(['name' => DemoName::LEGACY_PREFIX.'Старий відділ', 'status' => 'active']);
        $this->real['department'] = DB::table('departments')->insertGetId(['name' => 'Відділ продажів (див. [ТЕСТ])', 'status' => 'active']);

        foreach (['khanina_m@itstep.org', 'zaporozec_d@itstep.org', 'kryachko_o@itstep.org'] as $i => $email) {
            $this->real['user'.$i] = DB::table('users')->insertGetId(['email' => $email, 'name' => 'Real '.$i, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([$a, $this->real['branch']] as $branch) { // khanina sees all branches
            DB::table('branch_user')->insert(['user_id' => $this->real['user0'], 'branch_id' => $branch, 'created_at' => $now, 'updated_at' => $now]);
        }
        $oldUser = DB::table('users')->insertGetId(['email' => 'demo+old@sinhrm.test', 'name' => 'Old test', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('branch_user')->insert(['user_id' => $oldUser, 'branch_id' => $b, 'created_at' => $now, 'updated_at' => $now]);

        DB::table('employees')->insert(['user_id' => $oldUser, 'full_name' => 'Анна Тестенко', 'status' => 'active', 'hired_at' => '2024-01-01', 'branch_id' => $b, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('employees')->insert(['user_id' => null, 'full_name' => DemoName::LEGACY_PREFIX.'Старий', 'status' => 'active', 'hired_at' => '2024-01-01', 'department_id' => $oldDepartment, 'created_at' => $now, 'updated_at' => $now]);
        $this->real['employee'] = DB::table('employees')->insertGetId(['user_id' => $this->real['user1'], 'full_name' => 'Анна Тестенкова', 'status' => 'active', 'hired_at' => '2024-01-01',
            'branch_id' => $this->real['branch'], 'department_id' => $this->real['department'], 'created_at' => $now, 'updated_at' => $now]);

        $pipeline = DB::table('pipelines')->where('is_default', true)->value('id');
        DB::table('vacancies')->insert(['title' => 'тест', 'branch_id' => $a, 'recruiter_id' => $this->real['user0'], 'pipeline_id' => $pipeline, 'created_at' => $now, 'updated_at' => $now]);
        $this->real['vacancy'] = DB::table('vacancies')->insertGetId(['title' => 'Тестувальник QA', 'branch_id' => $this->real['branch'], 'recruiter_id' => $this->real['user0'], 'pipeline_id' => $pipeline, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('candidates')->insert(['full_name' => DemoName::LEGACY_PREFIX.'Старий кандидат', 'source' => 'manual', 'created_at' => $now, 'updated_at' => $now]);
        $this->real['candidate'] = DB::table('candidates')->insertGetId(['full_name' => 'Іван Тест', 'source' => 'manual', 'created_at' => $now, 'updated_at' => $now]);
    }

    private function realSnapshot(): string
    {
        return (string) json_encode([
            'users' => DB::table('users')->whereIn('id', [$this->real['user0'], $this->real['user1'], $this->real['user2']])->orderBy('id')->get(['id', 'email', 'name', 'status'])->toArray(),
            'branch_user' => DB::table('branch_user')->where('user_id', $this->real['user0'])->where('branch_id', $this->real['branch'])->count(),
            'branch' => DB::table('branches')->where('id', $this->real['branch'])->value('name'),
            'department' => DB::table('departments')->where('id', $this->real['department'])->value('name'),
            'employee' => DB::table('employees')->where('id', $this->real['employee'])->get(['full_name', 'branch_id', 'department_id', 'user_id'])->toArray(),
            'vacancy' => DB::table('vacancies')->where('id', $this->real['vacancy'])->get(['title', 'branch_id'])->toArray(),
            'candidate' => DB::table('candidates')->where('id', $this->real['candidate'])->value('full_name'),
        ]);
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
