<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI suggestion for the queue (never applied automatically: the superadmin confirms a rule).
        Schema::table('unknown_senders', function (Blueprint $table): void {
            // pending | done | failed; null = AI was not asked
            $table->string('ai_status', 16)->nullable();
            // job_board | candidate | colleague | newsletter | ignore
            $table->string('ai_kind', 16)->nullable();
            $table->string('ai_parser', 16)->nullable();
            $table->decimal('ai_confidence', 4, 3)->nullable();
            // {full_name, phone, email, vacancy_title} from a candidate application; shown as prefill only.
            $table->jsonb('ai_extracted')->nullable();
            $table->foreignId('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('unknown_senders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ai_request_id');
            $table->dropColumn(['ai_status', 'ai_kind', 'ai_parser', 'ai_confidence', 'ai_extracted']);
        });
    }
};
