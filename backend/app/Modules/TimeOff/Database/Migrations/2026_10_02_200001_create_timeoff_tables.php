<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 32)->unique();
            $table->boolean('paid')->default(true);
            // days | hours (requests are counted in working days either way)
            $table->string('unit', 8)->default('days');
            $table->string('color', 16)->default('#4f7cff');
            $table->boolean('requires_approval')->default(true);
            // false = unlimited (sick leave, unpaid day off): no balance check, no accrual
            $table->boolean('tracks_balance')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // branch_id null = the company default; a branch policy overrides it.
        Schema::create('leave_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            // yearly_upfront | monthly
            $table->string('accrual_mode', 16)->default('yearly_upfront');
            $table->decimal('annual_days', 6, 2);
            // null = carry everything over; otherwise the unused balance above it expires on Jan 1
            $table->decimal('carry_over_max', 6, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['leave_type_id', 'branch_id']);
        });

        Schema::create('holidays', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('name');
            // null = every branch
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->timestamps();
            $table->index('date');
        });

        // Balance = sum(delta). Append-only; corrections are new rows.
        Schema::create('leave_balance_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->decimal('delta', 8, 2);
            // accrual | request | adjustment | carry_over | expiry
            $table->string('reason', 16);
            // leave request id for reason=request
            $table->unsignedBigInteger('reference_id')->nullable();
            // Accrual/expiry idempotency key: "2026" (yearly, expiry) or "2026-10" (monthly); null otherwise.
            $table->string('period', 7)->nullable();
            $table->string('comment', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            // NULL periods never collide, so request/adjustment rows are unaffected.
            $table->unique(['employee_id', 'leave_type_id', 'reason', 'period'], 'leave_ledger_period_unique');
            $table->index(['employee_id', 'leave_type_id']);
        });

        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types');
            $table->date('starts_on');
            $table->date('ends_on');
            // none | start | end
            $table->string('half_day', 8)->default('none');
            // working days (Mon–Fri minus holidays, half days = 0.5), computed by the server
            $table->decimal('days', 6, 2);
            $table->text('comment')->nullable();
            // pending | approved | rejected | cancelled
            $table->string('status', 16)->default('pending');
            $table->boolean('balance_override')->default(false);
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'starts_on']);
            $table->index(['status', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balance_ledger');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('leave_policies');
        Schema::dropIfExists('leave_types');
    }
};
