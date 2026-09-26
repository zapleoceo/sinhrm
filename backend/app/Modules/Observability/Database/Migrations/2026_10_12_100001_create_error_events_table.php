<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app error log (docs/architecture/observability.md): one row per distinct error (fingerprint) with a counter.
 * No request body, no personal data: only the scrubbed message, the code location, the route name and a user id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_events', function (Blueprint $table): void {
            $table->id();
            // sha256 of source|class|file|line — the grouping key.
            $table->string('fingerprint', 64)->unique();
            // server | web
            $table->string('source', 8);
            $table->string('exception_class', 255);
            $table->text('message');
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('route', 255)->nullable();
            $table->unsignedBigInteger('last_user_id')->nullable();
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_events');
    }
};
