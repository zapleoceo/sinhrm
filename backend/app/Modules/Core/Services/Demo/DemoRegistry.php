<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Demo;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Remembers every root row the demo fill created (table + id) in demo_records. Reset deletes only rows listed
 * here (plus their dependent rows, found by foreign key to a listed row) — never anything else.
 */
final class DemoRegistry
{
    /** Marker rows "step:<name>" (record_id 0) remember which fill steps are done; they point at no table. */
    public const string STEP_PREFIX = 'step:';

    /**
     * Dependent rows created by module services (not registered one by one): table => [fk column, parent table].
     * Deleted before the parents, so reset never relies on ON DELETE CASCADE.
     */
    private const array CHILDREN = [
        ['tasks', 'candidate_id', 'candidates'],
        ['tasks', 'assignee_id', 'users'],
        ['tasks', 'employee_id', 'employees'],
        ['one_on_ones', 'employee_id', 'employees'],
        ['one_on_ones', 'manager_employee_id', 'employees'],
        ['script_evaluations', 'touchpoint_id', 'touchpoints'],
        ['touchpoints', 'candidate_id', 'candidates'],
        ['stage_changes', 'by_user_id', 'users'],
        ['application_interviewers', 'user_id', 'users'],
        ['candidate_screenings', 'candidate_id', 'candidates'],
        ['applications', 'candidate_id', 'candidates'],
        ['survey_responses', 'wave_id', 'survey_waves'],
        ['survey_wave_members', 'wave_id', 'survey_waves'],
        ['mood_checkins', 'employee_id', 'employees'],
        ['time_entries', 'timesheet_id', 'timesheets'],
        ['timesheets', 'employee_id', 'employees'],
        ['leave_balance_ledger', 'employee_id', 'employees'],
        ['leave_requests', 'employee_id', 'employees'],
        ['employee_compensations', 'employee_id', 'employees'],
        ['objective_checkins', 'objective_id', 'objectives'],
        ['review_answers', 'assignment_id', 'review_assignments'],
        ['kb_votes', 'article_id', 'kb_articles'],
        ['kb_article_versions', 'article_id', 'kb_articles'],
        ['asset_assignments', 'asset_id', 'assets'],
        ['hiring_request_approvals', 'hiring_request_id', 'hiring_requests'],
        ['branch_user', 'user_id', 'users'],
    ];

    /**
     * Foreign keys without ON DELETE (RESTRICT) to demo dictionaries from rows people create by hand: a demo branch
     * that a real vacancy or hiring request still uses is kept (unregistered), so reset never fails or touches real rows.
     */
    private const array RESTRICTED = [
        'branches' => [['vacancies', 'branch_id'], ['hiring_requests', 'branch_id']],
    ];

    /** @var list<array{table_name: string, record_id: int, created_at: Carbon}> */
    private array $pending = [];

    public function filled(): bool
    {
        return DB::table('demo_records')->exists();
    }

    public function add(string $table, int $id): int
    {
        $this->pending[] = ['table_name' => $table, 'record_id' => $id, 'created_at' => Carbon::now()];
        if (count($this->pending) >= 200) {
            $this->flush();
        }

        return $id;
    }

    public function has(string $table): bool
    {
        $this->flush();

        return DB::table('demo_records')->where('table_name', $table)->exists();
    }

    public function flush(): void
    {
        foreach (array_chunk($this->pending, 200) as $chunk) {
            DB::table('demo_records')->insertOrIgnore($chunk);
        }
        $this->pending = [];
    }

    /** @return list<int> */
    public function ids(string $table): array
    {
        $this->flush();

        return array_values(array_map('intval', DB::table('demo_records')->where('table_name', $table)->orderBy('record_id')->pluck('record_id')->all()));
    }

    /**
     * Deletes the registered rows (newest registration first) and their dependents.
     *
     * @return array<string, int> deleted rows per table
     */
    public function purge(): array
    {
        $this->flush();
        $deleted = [];
        foreach (self::CHILDREN as [$child, $column, $parent]) {
            foreach (array_chunk($this->ids($parent), 500) as $ids) {
                $n = DB::table($child)->whereIn($column, $ids)->delete();
                $deleted[$child] = ($deleted[$child] ?? 0) + $n;
            }
        }
        foreach (array_chunk($this->ids('users'), 500) as $ids) {
            DB::table('model_has_roles')->where('model_type', 'App\Models\User')->whereIn('model_id', $ids)->delete();
        }
        $tables = DB::table('demo_records')->where('table_name', 'not like', self::STEP_PREFIX.'%')->select('table_name')->groupBy('table_name')
            ->orderByRaw('MAX(id) DESC')->pluck('table_name')->all();
        // Newest registrations first; a row a foreign key still holds (e.g. a legacy branch registered after the rows
        // that use it) is retried on the next pass, after its users are gone. A row that never frees up is kept.
        for ($pass = 0, $progress = true; $pass < 4 && $progress; $pass++) {
            $progress = false;
            foreach ($tables as $table) {
                $table = (string) $table;
                foreach (array_chunk($this->ids($table), 500) as $ids) {
                    $ids = DB::table($table)->whereIn('id', $this->unreferenced($table, $ids))->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                    $n = $this->delete($table, $ids);
                    $deleted[$table] = ($deleted[$table] ?? 0) + $n;
                    $progress = $progress || $n > 0;
                }
            }
        }
        DB::table('demo_records')->delete();

        return array_filter($deleted);
    }

    /**
     * Deletes the rows in a savepoint (a failing statement would abort the whole Postgres transaction); when a foreign
     * key refuses the chunk, row by row, skipping the rows still in use.
     *
     * @param  list<int>  $ids
     */
    private function delete(string $table, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        try {
            return DB::transaction(static fn (): int => DB::table($table)->whereIn('id', $ids)->delete());
        } catch (QueryException) {
            $n = 0;
            foreach ($ids as $id) {
                try {
                    $n += DB::transaction(static fn (): int => DB::table($table)->where('id', $id)->delete());
                } catch (QueryException) {
                    // still referenced by a row outside the demo: kept
                }
            }

            return $n;
        }
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function unreferenced(string $table, array $ids): array
    {
        foreach (self::RESTRICTED[$table] ?? [] as [$child, $column]) {
            $used = array_map('intval', DB::table($child)->whereIn($column, $ids)->distinct()->pluck($column)->all());
            $ids = array_values(array_diff($ids, $used));
        }

        return $ids;
    }
}
