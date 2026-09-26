<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI screening of an application (candidate × vacancy requirements), tz6. Advisory only: a person decides.
        Schema::create('candidate_screenings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('vacancy_id')->constrained('vacancies')->cascadeOnDelete();
            // pending | done | failed
            $table->string('status', 16)->default('pending');
            // manual (button in the card) | auto (new application, setting ai_screening_auto)
            $table->string('trigger', 8);
            $table->unsignedSmallInteger('score')->nullable();
            // fit | maybe | no — derived from the score on the server
            $table->string('verdict', 8)->nullable();
            $table->string('summary', 400)->nullable();
            $table->jsonb('strengths')->nullable();
            $table->jsonb('gaps')->nullable();
            $table->jsonb('questions')->nullable();
            // e.g. screening.v1 (docs/modules/ai.md)
            $table->string('prompt_version', 32);
            $table->foreignId('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
            $table->string('error', 64)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'id']);
            $table->index(['candidate_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_screenings');
    }
};
