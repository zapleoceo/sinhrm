<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A recruiter script (call or chat). Its content lives in versions; the active one drives hints and evaluation.
        Schema::create('scripts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // call | chat (App\Modules\Scripts\Enums\ScriptChannel)
            $table->string('channel', 8);
            // FK added below: script_versions is created after scripts.
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();
            $table->index(['channel', 'archived']);
        });

        // Immutable once published (published_at set); published_at null = the single editable draft of the script.
        Schema::create('script_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('script_id')->constrained('scripts')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            // [{id, title, goal, sample, required, weight, keywords[]}]
            $table->jsonb('steps');
            // [{id, trigger, answer}]
            $table->jsonb('objections');
            // [{id, key, title, text}]: text with {Ім'я}, {Рекрутер}, {Вакансія}, … variables
            $table->jsonb('templates');
            // [{id, condition: no_reply|link_not_completed|gone_silent, delay_days, template_key}]
            $table->jsonb('followups');
            // {positive: [regex…], negative: [regex…]}: "next step fixed" heuristic of the rules evaluator
            $table->jsonb('next_step_patterns');
            $table->timestamps();
            $table->unique(['script_id', 'version']);
        });

        Schema::table('scripts', function (Blueprint $table): void {
            $table->foreign('active_version_id')->references('id')->on('script_versions')->nullOnDelete();
        });

        // One evaluation per touchpoint (call transcript or outbound chat message), bound to the version used.
        Schema::create('script_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('touchpoint_id')->unique()->constrained('touchpoints')->cascadeOnDelete();
            $table->foreignId('script_version_id')->constrained('script_versions');
            // rules | ai
            $table->string('engine', 8);
            $table->unsignedSmallInteger('score');
            // {steps[], next_step{}, objections[], recommendations[]}
            $table->jsonb('result');
            $table->timestamp('created_at')->nullable();
            $table->index('created_at');
        });

        // Recruiter tasks: follow-ups generated from script rules (ops job) or created by hand.
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('candidate_id')->nullable()->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applications')->cascadeOnDelete();
            // followup | manual
            $table->string('type', 16);
            $table->string('title');
            $table->timestamp('due_at');
            $table->timestamp('done_at')->nullable();
            $table->string('template_key', 64)->nullable();
            // "<script_id>:<followup id>": a follow-up rule fires once per application (idempotent job).
            $table->string('rule_key', 100)->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'rule_key']);
            $table->index(['assignee_id', 'done_at', 'due_at']);
            $table->index(['candidate_id', 'done_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('script_evaluations');
        Schema::table('scripts', function (Blueprint $table): void {
            $table->dropForeign(['active_version_id']);
        });
        Schema::dropIfExists('script_versions');
        Schema::dropIfExists('scripts');
    }
};
