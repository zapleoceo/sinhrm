<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->id();
            // Who: null = system (cron, queue, sign-up). No FK: the history must survive a deleted user.
            $table->unsignedBigInteger('user_id')->nullable();
            // What: logical entity key (employee, candidate, …) + its id.
            $table->string('entity_type', 48);
            $table->unsignedBigInteger('entity_id');
            // created | updated | deleted | status_changed | … (App\Modules\Audit\Enums\AuditAction)
            $table->string('action', 32);
            // {field: {from, to}}; sensitive fields are masked before they get here.
            $table->jsonb('changes')->nullable();
            // Extra non-personal context (secret name, prompt version, related candidate id).
            $table->jsonb('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id', 'id']);
            $table->index(['user_id', 'id']);
            $table->index(['action', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
