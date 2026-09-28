<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Demo;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Remembers every root row the demo fill created (table + id) in demo_records. Reset deletes only rows listed
 * here (plus their dependent rows, found by foreign key to a listed row) — never anything else.
 */
final class DemoRegistry
{
    /**
     * Dependent rows created by module services (not registered one by one): table => [fk column, parent table].
     * Deleted before the parents, so reset never relies on ON DELETE CASCADE.
     */
    private const array CHILDREN = [
        ['tasks', 'candidate_id', 'candidates'],
        ['tasks', 'assignee_id', 'users'],
        ['tasks', 'employee_id', 'employees'],
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
        return array_values(array_map('intval', DB::table('demo_records')->where('table_name', $table)->pluck('record_id')->all()));
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
        $tables = DB::table('demo_records')->select('table_name')->groupBy('table_name')
            ->orderByRaw('MAX(id) DESC')->pluck('table_name')->all();
        foreach ($tables as $table) {
            $table = (string) $table;
            foreach (array_chunk($this->ids($table), 500) as $ids) {
                $n = DB::table($table)->whereIn('id', $ids)->delete();
                $deleted[$table] = ($deleted[$table] ?? 0) + $n;
            }
        }
        DB::table('demo_records')->delete();

        return array_filter($deleted);
    }
}
