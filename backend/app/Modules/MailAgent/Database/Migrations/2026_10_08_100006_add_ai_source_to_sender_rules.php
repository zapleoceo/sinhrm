<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner decision: a confident AI classification (≥ 0.85) creates the rule itself; it stays visible and deletable.
        Schema::table('sender_rules', function (Blueprint $table): void {
            // manual (superadmin) | ai (auto-applied AI classification)
            $table->string('source', 8)->default('manual');
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->string('prompt_version', 32)->nullable();
            $table->foreignId('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('sender_rules', function (Blueprint $table): void {
            $table->dropIndex(['source']);
            $table->dropConstrainedForeignId('ai_request_id');
            $table->dropColumn(['source', 'ai_confidence', 'prompt_version']);
        });
    }
};
