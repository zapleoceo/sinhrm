<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // onboarding | offboarding | custom
            $table->string('kind', 16);
            // manual | employee_hired | employee_terminated | probation_end
            $table->string('trigger', 32)->default('manual');
            $table->boolean('active')->default(true);
            // probation_end: anchor date = hired_at + probation_days.
            $table->unsignedSmallInteger('probation_days')->default(90);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['trigger', 'active']);
        });

        Schema::create('workflow_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('workflow_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title');
            // create_task | request_form | send_email_template | add_calendar_event | create_document |
            // upload_document_request | webhook | start_workflow | notify_manager | assign_buddy
            $table->string('action', 32);
            // Days relative to the anchor date (hired_at / fired_at / start); negative = before.
            $table->smallInteger('offset_days')->default(0);
            // employee | manager | hr_admin | specific_user
            $table->string('assignee_rule', 16);
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Per-action settings, validated by the action's executor (never secrets: the webhook key is in the vault).
            $table->jsonb('config');
            $table->timestamps();
            $table->index(['template_id', 'position']);
        });

        Schema::create('workflow_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('workflow_templates')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('template_name');
            $table->date('anchor_date');
            // running | completed | cancelled
            $table->string('status', 16)->default('running');
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            // Automatic starts: the trigger value — one run per (template, employee, trigger). Manual: null.
            $table->string('trigger_key', 32)->nullable();
            $table->foreignId('parent_run_id')->nullable()->constrained('workflow_runs')->nullOnDelete();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['template_id', 'employee_id', 'trigger_key']);
            $table->index(['employee_id', 'status']);
        });

        // Steps of a run: a snapshot of the template step at start (later template edits do not touch running runs).
        Schema::create('workflow_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->foreignId('step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->jsonb('snapshot');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at');
            // pending | done | skipped | failed
            $table->string('status', 16)->default('pending');
            // Set when the executor has run (a task-creating step stays pending until the task is done).
            $table->timestamp('executed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            // Codes and ids only (task_id, document_id, error code) — never payloads or secrets.
            $table->jsonb('result')->nullable();
            $table->timestamps();
            $table->index(['status', 'executed_at', 'due_at']);
            $table->index(['run_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_run_steps');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_templates');
    }
};
