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
        // Journal of personal-data requests (who exported/erased whom, why). Counters only, never the data itself.
        Schema::create('privacy_requests', function (Blueprint $table): void {
            $table->id();
            // candidate | employee
            $table->string('subject_type', 16);
            $table->unsignedBigInteger('subject_id');
            // export | erase
            $table->string('action', 8);
            // manual | retention
            $table->string('trigger', 16)->default('manual');
            $table->string('reason', 500)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('counts')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['subject_type', 'subject_id']);
        });

        // The single settings row. retention_rejected_months null = auto-anonymization off (default).
        Schema::create('privacy_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('retention_rejected_months')->nullable();
            $table->timestamps();
        });
        DB::table('privacy_settings')->insert(['retention_rejected_months' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_settings');
        Schema::dropIfExists('privacy_requests');
    }
};
