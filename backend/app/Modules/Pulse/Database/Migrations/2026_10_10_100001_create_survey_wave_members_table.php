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
            // Which APP_KEY made the fingerprint (first 16 hex of SHA-256 over a label + the key; not the key). After a
            // key rotation old fingerprints no longer match: they are re-keyed via APP_PREVIOUS_KEYS, otherwise the
            // comparison is "unknown" and the group is hidden (fail-closed), never "everyone changed, so allowed".
            $table->char('key_id', 16);
            $table->primary(['wave_id', 'member']);
        });

        // Differencing decision per segment, made once when the wave closes (closed waves never change):
        // {"department_id": {"s:<id>": bool, "c:<id>": bool}, "branch_id": {...}}. Never serialized by the API.
        Schema::table('survey_waves', function (Blueprint $table): void {
            $table->jsonb('segment_visibility')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('survey_waves', function (Blueprint $table): void {
            $table->dropColumn('segment_visibility');
        });
        Schema::dropIfExists('survey_wave_members');
    }
};
