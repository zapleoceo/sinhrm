<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('script_evaluations', function (Blueprint $table): void {
            // AI evaluations: which prompt text produced them (e.g. script_eval.v1) and the ai_requests row.
            $table->string('prompt_version', 32)->nullable();
            $table->foreignId('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('script_evaluations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ai_request_id');
            $table->dropColumn('prompt_version');
        });
    }
};
