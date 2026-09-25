<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configurable pipelines: every vacancy runs through the stages of one pipeline.
        Schema::create('pipelines', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('pipeline_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('pipelines')->cascadeOnDelete();
            $table->string('name');
            // attract | select | hire | closed (App\Modules\Recruiting\Enums\StageKind)
            $table->string('kind', 16);
            $table->unsignedSmallInteger('position');
            $table->boolean('is_terminal')->default(false);
            $table->timestamps();
            $table->unique(['pipeline_id', 'position']);
        });

        // Manageable dictionary of rejection reasons; never deleted, only deactivated.
        Schema::create('reject_reasons', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('vacancies', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('recruiter_id')->constrained('users');
            $table->foreignId('pipeline_id')->constrained('pipelines');
            // open | paused | closed
            $table->string('status', 16)->default('open');
            $table->text('description')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });

        Schema::create('candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name');
            // Normalized contacts are the dedupe keys: phone E.164, email lowercase, telegram without "@", lowercase.
            $table->string('phone', 20)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('telegram_username', 64)->nullable()->index();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->string('source', 32)->default('manual');
            $table->jsonb('utm')->nullable();
            $table->jsonb('tags')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('source');
        });

        Schema::create('applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('vacancy_id')->constrained('vacancies')->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained('pipeline_stages');
            // active | hired | rejected
            $table->string('status', 16)->default('active');
            $table->foreignId('reject_reason_id')->nullable()->constrained('reject_reasons')->nullOnDelete();
            $table->text('rejected_note')->nullable();
            $table->timestamp('stage_entered_at')->nullable();
            // Last real contact (any channel except system); kept by the UpdateLastTouch listener.
            $table->timestamp('last_touch_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['candidate_id', 'vacancy_id']);
            $table->index(['vacancy_id', 'stage_id']);
            $table->index(['status', 'last_touch_at']);
        });

        Schema::create('stage_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('from_stage_id')->nullable()->constrained('pipeline_stages');
            $table->foreignId('to_stage_id')->constrained('pipeline_stages');
            $table->foreignId('by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('at');
            $table->index(['application_id', 'at']);
        });

        Schema::create('touchpoints', function (Blueprint $table): void {
            $table->id();
            // candidate_id null → an unmatched message waiting in the inbox.
            $table->foreignId('candidate_id')->nullable()->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            // Branch of the line/account that received the message: scopes the inbox for recruiters.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            // Set on the system touchpoint of a stage change (the timeline shows the stage change itself).
            $table->foreignId('stage_change_id')->nullable()->constrained('stage_changes')->cascadeOnDelete();
            // call | telegram | whatsapp | viber | email | note | meeting | system
            $table->string('channel', 16);
            // in | out
            $table->string('direction', 3)->default('out');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->text('body')->nullable();
            // duration_sec, recording_url, contact, … (never secrets)
            $table->jsonb('meta')->nullable();
            // Id of the message/call in the source system: dedupe key per channel (NULLs never collide).
            $table->string('external_id', 191)->nullable();
            $table->boolean('via_product')->default(false);
            $table->string('integration_key', 64)->nullable();
            $table->timestamps();
            $table->unique(['channel', 'external_id']);
            $table->index(['candidate_id', 'occurred_at']);
            $table->index(['author_id', 'occurred_at']);
            $table->index('occurred_at');
        });

        // Unmatched inbox as a view (for SQL reports/BI); the API reads touchpoints with candidate_id IS NULL.
        DB::statement('DROP VIEW IF EXISTS unmatched_messages');
        DB::statement('CREATE VIEW unmatched_messages AS SELECT * FROM touchpoints WHERE candidate_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS unmatched_messages');
        Schema::dropIfExists('touchpoints');
        Schema::dropIfExists('stage_changes');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('candidates');
        Schema::dropIfExists('vacancies');
        Schema::dropIfExists('reject_reasons');
        Schema::dropIfExists('pipeline_stages');
        Schema::dropIfExists('pipelines');
    }
};
