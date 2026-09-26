<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hiring requests (tz2): a manager asks for a position, the request goes through a configurable approval route,
 * and on final approval a vacancy is opened and linked. Route steps are copied into the request on submit
 * (hiring_request_approvals) so later changes of the route never affect requests in flight.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row: the configurable form (extra fields), who may create requests besides admins/managers, auto-vacancy.
        Schema::create('hiring_request_settings', function (Blueprint $table): void {
            $table->id();
            $table->jsonb('form_fields')->nullable();
            $table->jsonb('creator_user_ids')->nullable();
            $table->boolean('auto_vacancy')->default(true);
            $table->timestamps();
        });

        // The approval route template: steps in order. kind: manager (the requester's manager) | role | user.
        Schema::create('hiring_route_steps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('position');
            $table->string('name', 120);
            $table->string('kind', 16);
            $table->string('role', 32)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('sla_days')->nullable();
            $table->timestamps();
            $table->unique('position');
        });

        Schema::create('hiring_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 255);
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->unsignedSmallInteger('headcount')->default(1);
            // new_position | replacement
            $table->string('reason', 16);
            $table->foreignId('replaced_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('desired_start_date')->nullable();
            $table->decimal('salary_min', 12, 2)->nullable();
            $table->decimal('salary_max', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('requirements')->nullable();
            // low | normal | high | urgent
            $table->string('priority', 8)->default('normal');
            // Values of the configurable form fields (only declared keys).
            $table->jsonb('extra')->nullable();
            // draft | pending | approved | rejected | cancelled | in_progress | closed
            $table->string('status', 16)->default('draft');
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recruiter_id')->nullable()->constrained('users')->nullOnDelete();
            // One vacancy per request (idempotency of the auto-created vacancy).
            $table->foreignId('vacancy_id')->nullable()->unique()->constrained('vacancies')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('requester_id');
        });

        Schema::create('hiring_request_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hiring_request_id')->constrained('hiring_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 120);
            $table->string('kind', 16);
            $table->string('role', 32)->nullable();
            // Resolved approver for manager/user steps; null for role steps (any active user with the role).
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('sla_days')->nullable();
            // waiting | pending | approved | rejected | skipped
            $table->string('status', 16)->default('waiting');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('comment')->nullable();
            // Notification tasks created for this step (idempotency of the "task for the next approver").
            $table->boolean('notified')->default(false);
            // Overdue escalation already sent (hiring.sla).
            $table->boolean('escalated')->default(false);
            $table->timestamps();
            $table->unique(['hiring_request_id', 'position']);
            $table->index(['status', 'due_at']);
        });

        $now = Carbon::now();
        DB::table('hiring_request_settings')->insert(['form_fields' => '[]', 'creator_user_ids' => '[]', 'auto_vacancy' => true, 'created_at' => $now, 'updated_at' => $now]);
        // Default route (tz2): the requester's manager → HR (admins). A branch director step is added in the settings.
        DB::table('hiring_route_steps')->insert([
            ['position' => 1, 'name' => 'Manager', 'kind' => 'manager', 'role' => null, 'user_id' => null, 'sla_days' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['position' => 2, 'name' => 'HR', 'kind' => 'role', 'role' => 'admin', 'user_id' => null, 'sla_days' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('hiring_request_approvals');
        Schema::dropIfExists('hiring_requests');
        Schema::dropIfExists('hiring_route_steps');
        Schema::dropIfExists('hiring_request_settings');
    }
};
