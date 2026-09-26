<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Edited prompt versions from the admin prompt editor. Only the instruction part (ROLE/TASK/RULES) is stored;
        // OUTPUT and the JSON schema stay in code. No active row for a purpose = the built-in (code) version is used.
        Schema::create('ai_prompt_versions', function (Blueprint $table): void {
            $table->id();
            // script_evaluation | mail_classification | candidate_screening (App\Modules\Ai\Enums\AiPurpose)
            $table->string('purpose', 32);
            // e.g. screening.v4-custom-1 — written to ai_requests.prompt_version while active.
            $table->string('version', 32)->unique();
            // Built-in version the edit was made from (screening.v4).
            $table->string('base_version', 32);
            $table->text('body');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(false);
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->index(['purpose', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_versions');
    }
};
