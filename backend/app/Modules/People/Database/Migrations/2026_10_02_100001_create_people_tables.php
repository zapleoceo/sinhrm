<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An employee may exist without a login (user_id null); one user is linked to at most one employee.
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('full_name');
            // Directory tier: visible to every active user.
            $table->string('work_email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('avatar_url', 512)->nullable();
            // PII tier: admins and the employee only.
            $table->date('birth_date')->nullable();
            $table->string('personal_email')->nullable();
            $table->text('address')->nullable();
            $table->text('emergency_contact')->nullable();
            $table->jsonb('custom_fields')->nullable();
            // Job tier: admins, the employee and their managers.
            $table->date('hired_at');
            $table->date('fired_at')->nullable();
            $table->string('termination_reason', 500)->nullable();
            // active | on_leave | terminated
            $table->string('status', 16)->default('active');
            // full_time | part_time | contractor
            $table->string('employment_type', 16)->default('full_time');
            $table->jsonb('work_schedule')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            // Hire from Recruiting: one employee per candidate / application (idempotency keys).
            $table->foreignId('candidate_id')->nullable()->unique()->constrained('candidates')->nullOnDelete();
            $table->foreignId('application_id')->nullable()->unique()->constrained('applications')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'full_name']);
            $table->index('manager_id');
            $table->index('branch_id');
        });

        // Self-service: the employee asks to change whitelisted personal fields; an admin or a manager decides.
        Schema::create('employee_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('changes');
            // pending | approved | rejected
            $table->string('status', 16)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('comment')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamps();
            $table->index(['status', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_change_requests');
        Schema::dropIfExists('employees');
    }
};
