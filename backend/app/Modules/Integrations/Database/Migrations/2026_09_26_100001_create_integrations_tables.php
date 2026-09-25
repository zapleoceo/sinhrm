<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            // off | demo | connected | error (App\Modules\Integrations\Enums\IntegrationStatus)
            $table->string('status', 16)->default('off');
            // Non-secret config only; secrets live in integration_secrets.
            $table->jsonb('settings')->default('{}');
            $table->timestamp('last_checked_at')->nullable();
            // Scrubbed error text; never contains secret values.
            $table->string('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_secrets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('name', 64);
            // Ciphertext (Laravel Crypt, APP_KEY): encrypted payload is much longer than the secret.
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['integration_id', 'name']);
        });

        Schema::create('integration_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('level', 16);
            $table->string('message');
            $table->jsonb('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['integration_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
        Schema::dropIfExists('integration_secrets');
        Schema::dropIfExists('integrations');
    }
};
