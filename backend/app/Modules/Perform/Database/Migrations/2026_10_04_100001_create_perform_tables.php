<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('one_on_one_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // list<string>: agenda points copied into a new 1:1
            $table->jsonb('agenda');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('one_on_ones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manager_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamp('scheduled_at');
            $table->foreignId('template_id')->nullable()->constrained('one_on_one_templates')->nullOnDelete();
            // list<{id, text, done}>
            $table->jsonb('agenda');
            // Seen by the manager of the meeting only (never the employee, never another admin through the API).
            $table->text('notes_private_manager')->nullable();
            $table->text('notes_shared')->nullable();
            // list<{id, text, done, due_on?}>
            $table->jsonb('action_items');
            // scheduled | completed | cancelled
            $table->string('status', 16)->default('scheduled');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'scheduled_at']);
            $table->index(['manager_employee_id', 'scheduled_at']);
        });

        Schema::create('objectives', function (Blueprint $table): void {
            $table->id();
            // personal | team | branch | company
            $table->string('scope', 16);
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            // "2026-Q4"
            $table->string('period', 7);
            $table->string('title');
            $table->text('description')->nullable();
            // list<{id, title, start, target, current, unit, weight}>
            $table->jsonb('key_results');
            // 0..100, recomputed from key_results on every write (ObjectiveProgress)
            $table->unsignedTinyInteger('progress')->default(0);
            // active | achieved | missed | cancelled
            $table->string('status', 16)->default('active');
            $table->foreignId('parent_objective_id')->nullable()->constrained('objectives')->nullOnDelete();
            // public | team | private
            $table->string('visibility', 16)->default('public');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['period', 'scope']);
            $table->index('owner_employee_id');
        });

        Schema::create('objective_checkins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('objective_id')->constrained('objectives')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('progress_before');
            $table->unsignedTinyInteger('progress_after');
            // snapshot: list<{id, current}>
            $table->jsonb('key_results');
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->index(['objective_id', 'created_at']);
        });

        Schema::create('kpis', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('metric');
            $table->string('unit', 32)->nullable();
            // "2026-10" (month) or "2026-Q4" (quarter)
            $table->string('period', 7);
            $table->decimal('target', 14, 2);
            $table->decimal('actual', 14, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'metric', 'period']);
        });

        Schema::create('feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('from_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('to_employee_id')->constrained('employees')->cascadeOnDelete();
            // praise | constructive | request
            $table->string('type', 16);
            $table->text('text');
            // private_to_recipient | manager | public
            $table->string('visibility', 24);
            // An answer points to the request it answers.
            $table->foreignId('request_id')->nullable()->constrained('feedback')->nullOnDelete();
            // A request: when it was answered.
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->index(['to_employee_id', 'created_at']);
            $table->index(['from_employee_id', 'created_at']);
        });

        Schema::create('rating_scales', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // list<{value: int, label: string}>, ascending
            $table->jsonb('levels');
            $table->timestamps();
        });

        Schema::create('competencies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('scale_id')->constrained('rating_scales')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('review_cycles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->date('period_start');
            $table->date('period_end');
            // {branch_ids: list<int>, department_ids: list<int>} — empty lists = everyone
            $table->jsonb('participants');
            // list of self | manager | peer | upward
            $table->jsonb('types');
            // list<int>
            $table->jsonb('competency_ids');
            // Peer / upward reviewers are never shown (and their results need ReviewResults::MIN_REVIEWERS).
            $table->boolean('anonymous')->default(true);
            // {self?: Y-m-d, manager?: Y-m-d, peer?: Y-m-d, upward?: Y-m-d}
            $table->jsonb('deadlines');
            // draft | active | closed
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('review_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained('review_cycles')->cascadeOnDelete();
            $table->foreignId('subject_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('reviewer_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type', 16);
            // pending | submitted
            $table->string('status', 16)->default('pending');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['cycle_id', 'subject_employee_id', 'reviewer_employee_id', 'type'], 'review_assignments_unique');
            $table->index(['reviewer_employee_id', 'status']);
        });

        Schema::create('review_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained('review_assignments')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained('competencies')->restrictOnDelete();
            $table->unsignedSmallInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['assignment_id', 'competency_id']);
        });

        Schema::create('development_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('title');
            // list<{id, text}>
            $table->jsonb('goals');
            // list<{id, text, due_on?, done}>
            $table->jsonb('actions');
            $table->date('due_on')->nullable();
            // active | completed | cancelled
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach ([
            'development_plans', 'review_answers', 'review_assignments', 'review_cycles', 'competencies',
            'rating_scales', 'feedback', 'kpis', 'objective_checkins', 'objectives', 'one_on_ones', 'one_on_one_templates',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
