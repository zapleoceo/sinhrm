<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One AI request (all attempts). No prompt and no answer text: ids, counters, codes only.
        // Daily caps are computed from this table (sum of attempts and cost_usd since 00:00 UTC).
        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->id();
            // script_evaluation | mail_classification | candidate_screening | test (App\Modules\Ai\Enums\AiPurpose)
            $table->string('purpose', 32);
            // What the result is applied to, e.g. touchpoint / unknown_sender / screening + its id.
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // Ids/flags only (e.g. script_version_id), never personal data.
            $table->jsonb('meta')->nullable();
            // ai_broker | openrouter
            $table->string('provider', 16);
            // Broker lane used (chat:fast | chat:smart | chat:sales | structured), per purpose in the settings.
            $table->string('capability', 32)->nullable();
            // Provider job id of the last attempt (null until submitted).
            $table->string('job_id', 64)->nullable();
            // pending | done | failed
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(1);
            // e.g. script_eval.v1 — which prompt text produced the result (docs/modules/ai.md).
            $table->string('prompt_version', 32);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('tokens_cached')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->string('model', 128)->nullable();
            // Error code (ai_invalid_output, ai_provider_http_401, ai_timeout, …), never a provider message.
            $table->string('error', 64)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
