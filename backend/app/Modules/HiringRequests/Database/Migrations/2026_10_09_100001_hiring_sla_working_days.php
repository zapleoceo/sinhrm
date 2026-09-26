<?php

declare(strict_types=1);

use App\Modules\Core\Contracts\WorkingCalendar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Approval SLA is counted in WORKING days (owner decision, default 2): Mon–Fri minus TimeOff holidays of the branch.
 * - the seeded "HR" step (role admin, 3 days, untouched by the admin) gets the new default of 2;
 * - pending steps in flight get due_at recomputed from activated_at in working days (a deadline only moves later,
 *   so nothing becomes overdue because of this migration; the escalated flag is kept as is).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('hiring_route_steps')
            ->where(['name' => 'HR', 'kind' => 'role', 'role' => 'admin', 'sla_days' => 3])
            ->update(['sla_days' => WorkingCalendar::DEFAULT_SLA_DAYS]);

        $calendar = app(WorkingCalendar::class);
        DB::table('hiring_request_approvals as a')
            ->join('hiring_requests as r', 'r.id', '=', 'a.hiring_request_id')
            ->where('a.status', 'pending')
            ->whereNotNull('a.sla_days')
            ->whereNotNull('a.activated_at')
            ->select(['a.id', 'a.sla_days', 'a.activated_at', 'r.branch_id'])
            ->orderBy('a.id')
            ->each(function (object $row) use ($calendar): void {
                $due = $calendar->addWorkingDays(Carbon::parse($row->activated_at), (int) $row->sla_days, $row->branch_id === null ? null : (int) $row->branch_id);
                DB::table('hiring_request_approvals')->where('id', $row->id)->update(['due_at' => $due]);
            });
    }

    public function down(): void
    {
        DB::table('hiring_route_steps')
            ->where(['name' => 'HR', 'kind' => 'role', 'role' => 'admin', 'sla_days' => WorkingCalendar::DEFAULT_SLA_DAYS])
            ->update(['sla_days' => 3]);
        // due_at of steps in flight stays in working days: a calendar-day deadline cannot be told apart afterwards.
    }
};
