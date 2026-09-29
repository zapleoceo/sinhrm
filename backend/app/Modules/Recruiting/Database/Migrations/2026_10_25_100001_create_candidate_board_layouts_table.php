<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal board on /candidates: the user's order of columns for one vacancy — one list mixing funnel stages
 * ("stage:<id>", their relative order is always the funnel order) and own columns ("col:<id>").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_board_layouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('vacancy_id')->constrained('vacancies')->cascadeOnDelete();
            $table->json('keys');
            $table->timestamps();
            $table->unique(['user_id', 'vacancy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_board_layouts');
    }
};
