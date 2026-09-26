<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contextual recruiting roles (docs/modules/recruiting.md, section "Команда найму"): a hiring manager per vacancy and
 * interviewers per application. They are assignments, not global roles — any active user may hold them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacancies', function (Blueprint $table): void {
            $table->foreignId('hiring_manager_id')->nullable()->after('recruiter_id')->constrained('users')->nullOnDelete();
            $table->index('hiring_manager_id');
        });
        Schema::create('application_interviewers', function (Blueprint $table): void {
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['application_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_interviewers');
        Schema::table('vacancies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hiring_manager_id');
        });
    }
};
