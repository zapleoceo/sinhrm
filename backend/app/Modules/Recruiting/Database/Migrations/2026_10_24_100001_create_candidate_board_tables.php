<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal board on /candidates: a user's own extra columns on top of the shared funnel stages of a vacancy and
 * where he filed each card. Pure view state: never touches applications.stage_id, not audited, no personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_board_columns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('scope_vacancy_id')->nullable()->constrained('vacancies')->cascadeOnDelete();
            $table->string('title', 40);
            $table->string('color', 16)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('hidden')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'scope_vacancy_id']);
        });

        Schema::create('candidate_board_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('column_id')->constrained('candidate_board_columns')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'application_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_board_cards');
        Schema::dropIfExists('candidate_board_columns');
    }
};
