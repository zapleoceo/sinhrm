<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who was ASKED in a closed wave (the audience at close), never who answered: used only to refuse showing
        // two aggregates of a group whose members differ by a handful of people (differencing). No answer, hash,
        // time or order links a row here to a row in survey_responses. Never returned by the API.
        Schema::create('survey_wave_members', function (Blueprint $table): void {
            $table->foreignId('wave_id')->constrained('survey_waves')->cascadeOnDelete();
            // HMAC-SHA256(APP_KEY, "member:" + employee id): equal for the same person in every wave, no raw id.
            $table->char('member', 64);
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->primary(['wave_id', 'member']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_wave_members');
    }
};
