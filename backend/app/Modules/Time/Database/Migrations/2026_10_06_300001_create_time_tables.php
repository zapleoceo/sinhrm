<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Time tracking: work schedules (company default + per branch; an employee's own People work_schedule wins),
 * weekly timesheets (one per employee and ISO week, Monday start) with entries, submit/approve by the manager.
 */
return new class extends Migration
{
    public function up(): void
    {
        // branch_id null = the company default. days: ISO weekdays 1 (Mon) … 7 (Sun).
        Schema::create('work_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->unique()->constrained('branches')->cascadeOnDelete();
            $table->jsonb('days');
            $table->decimal('hours_per_day', 4, 2);
            $table->timestamps();
        });

        Schema::create('timesheets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('week_start');
            // draft | submitted | approved | rejected
            $table->string('status', 16)->default('draft');
            // Totals of the week, refreshed on every save/submit (reports and lists read them).
            $table->decimal('expected_hours', 6, 2)->default(0);
            $table->decimal('worked_hours', 6, 2)->default(0);
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'week_start']);
            $table->index(['status', 'week_start']);
        });

        Schema::create('time_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('timesheet_id')->constrained('timesheets')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('hours', 5, 2);
            $table->string('project', 120)->nullable();
            $table->string('category', 60)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['timesheet_id', 'date']);
        });

        $now = Carbon::now();
        DB::table('work_schedules')->insert(['branch_id' => null, 'days' => '[1,2,3,4,5]', 'hours_per_day' => 8, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
        Schema::dropIfExists('timesheets');
        Schema::dropIfExists('work_schedules');
    }
};
