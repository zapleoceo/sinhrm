<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Demo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Test rows left by earlier fills that are NOT in demo_records (old "[ТЕСТ] …" names at the start, the first hand-made
 * test branches, the old Recruiting demo). Found only by STRICT patterns below; reset registers them first, so the
 * usual purge removes them with their dependents. A row that does not match a pattern is never touched; users are
 * matched only by the reserved test domain (real people never have it), so real accounts stay as they are.
 */
final class DemoLegacy
{
    /** table => column whose value starts with the old marker "[ТЕСТ] ". */
    private const array PREFIXED = [
        'cities' => 'name', 'branches' => 'name', 'departments' => 'name', 'positions' => 'name',
        'employees' => 'full_name', 'vacancies' => 'title', 'candidates' => 'full_name', 'scripts' => 'name',
        'rating_scales' => 'name', 'competencies' => 'name', 'review_cycles' => 'name', 'objectives' => 'title',
        'surveys' => 'title', 'desk_categories' => 'name', 'desk_cases' => 'subject', 'kb_categories' => 'name',
        'kb_articles' => 'title', 'asset_types' => 'name', 'assets' => 'name', 'hiring_requests' => 'title',
        'acquisition_channel_costs' => 'note',
    ];

    /** table => [column, exact legacy values] */
    private const array EXACT = [
        'branches' => ['name', ['Тестова філія А', 'Тестова філія Б', DemoName::BRANCH]],
        'vacancies' => ['title', ['тест']],
        'employees' => ['full_name', ['Анна Тестенко']],
    ];

    /** Registration order, parents first (purge deletes the newest registrations first). */
    private const array ORDER = [
        'cities', 'branches', 'departments', 'positions', 'users', 'employees', 'vacancies', 'candidates', 'scripts',
        'rating_scales', 'competencies', 'review_cycles', 'objectives', 'surveys', 'desk_categories', 'desk_cases',
        'kb_categories', 'kb_articles', 'asset_types', 'assets', 'hiring_requests', 'acquisition_channel_costs',
    ];

    /** Only this reserved domain marks a test user (demo+…@sinhrm.test); the marker in a name alone never does. */
    private const string USER_DOMAIN = '@sinhrm.test';

    public function __construct(private readonly DemoRegistry $registry) {}

    /** @return array<string, list<int>> unregistered legacy ids per table, parents first */
    public function find(): array
    {
        $out = [];
        foreach (self::ORDER as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $query = DB::table($table)->whereNotIn('id', $this->registry->ids($table));
            if ($table === 'users') {
                $query->where('email', 'like', '%'.self::USER_DOMAIN);
            } else {
                $query->where(function ($q) use ($table): void {
                    $q->orWhere(self::PREFIXED[$table], 'like', DemoName::LEGACY_PREFIX.'%');
                    if (isset(self::EXACT[$table])) {
                        $q->orWhereIn(self::EXACT[$table][0], self::EXACT[$table][1]);
                    }
                });
            }
            $ids = array_values(array_map('intval', $query->orderBy('id')->pluck('id')->all()));
            if ($ids !== []) {
                $out[$table] = $ids;
            }
        }

        return $out;
    }

    /**
     * What a reset would do, changing nothing: legacy rows per table, demo rows already registered per table, and the
     * side effects worth seeing first.
     *
     * @return array{legacy: array<string, int>, registered: array<string, int>, effects: array<string, int>}
     */
    public function report(): array
    {
        $legacy = $this->find();
        $registered = DB::table('demo_records')->where('table_name', 'not like', DemoRegistry::STEP_PREFIX.'%')
            ->groupBy('table_name')->selectRaw('table_name, count(*) as c')->pluck('c', 'table_name')->all();
        $branches = [...($legacy['branches'] ?? []), ...$this->registry->ids('branches')];
        $users = [...($legacy['users'] ?? []), ...$this->registry->ids('users')];
        $vacancies = $legacy['vacancies'] ?? [];
        $candidates = [...($legacy['candidates'] ?? []), ...$this->registry->ids('candidates')];

        return [
            'legacy' => array_map('count', $legacy),
            'registered' => array_map('intval', $registered),
            'effects' => [
                // real people lose only links to the test branches that are removed (their other branches stay)
                'real_user_links_to_test_branches' => DB::table('branch_user')->whereIn('branch_id', $branches)->whereNotIn('user_id', $users)->count(),
                // applications of non-test candidates to removed test vacancies (deleted with the vacancy)
                'other_applications_on_legacy_vacancies' => DB::table('applications')->whereIn('vacancy_id', $vacancies)->whereNotIn('candidate_id', $candidates)->count(),
                // non-test employees in removed test branches keep their record, the branch becomes empty (ON DELETE SET NULL)
                'other_employees_in_test_branches' => DB::table('employees')->whereIn('branch_id', $branches)->whereNotIn('id', [...($legacy['employees'] ?? []), ...$this->registry->ids('employees')])->count(),
                // test branches kept: a non-test vacancy or hiring request still uses them
                'branches_kept_in_use' => count(array_unique([
                    ...DB::table('vacancies')->whereIn('branch_id', $branches)->whereNotIn('id', [...$vacancies, ...$this->registry->ids('vacancies')])->pluck('branch_id')->all(),
                    ...DB::table('hiring_requests')->whereIn('branch_id', $branches)->whereNotIn('id', [...($legacy['hiring_requests'] ?? []), ...$this->registry->ids('hiring_requests')])->pluck('branch_id')->all(),
                ])),
            ],
        ];
    }

    /** @return array<string, int> registered legacy rows per table */
    public function register(): array
    {
        $found = $this->find();
        foreach ($found as $table => $ids) {
            foreach ($ids as $id) {
                $this->registry->add($table, $id);
            }
        }
        $this->registry->flush();

        return array_map('count', $found);
    }
}
