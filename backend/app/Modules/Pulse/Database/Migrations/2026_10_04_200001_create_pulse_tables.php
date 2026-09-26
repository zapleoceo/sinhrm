<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            // engagement | lifecycle | enps | mood | custom
            $table->string('type', 16);
            $table->text('description')->nullable();
            // list<{id, type: scale5|scale10|enps|single|multi|text, text, options?: list<string>, required}>
            $table->jsonb('questions');
            // lifecycle surveys only: hire_30 | hire_90 | exit
            $table->string('lifecycle_trigger', 16)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['lifecycle_trigger', 'active']);
        });

        Schema::create('survey_waves', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('survey_id')->constrained('surveys')->cascadeOnDelete();
            // The wave this one repeats (recurring schedules); one successor per wave.
            $table->foreignId('parent_wave_id')->nullable()->unique()->constrained('survey_waves')->nullOnDelete();
            // once | weekly | monthly | quarterly
            $table->string('schedule', 16)->default('once');
            // {branch_ids: list<int>, department_ids: list<int>} — empty = everyone
            $table->jsonb('audience');
            $table->boolean('anonymous')->default(true);
            // Aggregates of fewer respondents than this are never shown (anonymous waves: at least 5).
            $table->unsignedSmallInteger('min_group_size')->default(5);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            // scheduled | open | closed
            $table->string('status', 16)->default('scheduled');
            // Per-wave secret for respondent hashes; wiped when the wave closes (hashes can no longer be recomputed).
            $table->string('salt', 64)->nullable();
            // Lifecycle waves: the one employee asked, and "<trigger>:<date>" for idempotent starts.
            $table->foreignId('subject_employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->string('trigger_key', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['survey_id', 'subject_employee_id', 'trigger_key']);
            $table->index(['status', 'starts_at']);
        });

        // No timestamps on purpose: an exact submit time could be matched with login logs. Only the day is kept.
        Schema::create('survey_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wave_id')->constrained('survey_waves')->cascadeOnDelete();
            // HMAC-SHA256(APP_KEY, wave salt + employee id): one answer per person without storing who.
            $table->string('respondent_hash', 64);
            // Only for non-anonymous waves; always null for anonymous ones.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            // Segments for breakdowns (shown only for groups >= min_group_size).
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            // {question id: int | list<int> | string}
            $table->jsonb('answers');
            $table->date('submitted_on');
            $table->unique(['wave_id', 'respondent_hash']);
        });

        Schema::create('mood_settings', function (Blueprint $table): void {
            $table->id();
            // ISO weekdays (1 = Monday … 7 = Sunday) when the question is shown
            $table->jsonb('weekdays');
            $table->string('question');
            $table->boolean('required')->default(false);
            // Manager alert: team average dropped by at least this much week over week.
            $table->decimal('alert_drop', 3, 2)->default(0.5);
            $table->unsignedSmallInteger('min_group')->default(5);
            $table->timestamps();
        });

        Schema::create('mood_checkins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedTinyInteger('score');
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'day']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        foreach (['mood_checkins', 'mood_settings', 'survey_responses', 'survey_waves', 'surveys'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
