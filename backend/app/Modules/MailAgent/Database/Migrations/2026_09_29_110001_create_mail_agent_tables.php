<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How to treat mail of a sender: "a@b.c" (exact) or "@b.c" (domain and its subdomains).
        Schema::create('sender_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('pattern')->unique();
            // job_board | candidate | colleague | newsletter | ignore
            $table->string('kind', 16);
            // work_ua | robota_ua | djinni | generic (job_board only)
            $table->string('parser', 16)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        // Senders without a rule (and not candidates): only the address and a sample subject, never the body.
        Schema::create('unknown_senders', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('sample_subject')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('count')->default(1);
            $table->string('suggested_kind', 16)->nullable();
            $table->string('suggested_parser', 16)->nullable();
            $table->timestamps();
        });

        // Processed-mail log (idempotency by Gmail id + "recent processed" list). No bodies.
        Schema::create('mail_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('gmail_id', 64)->unique();
            $table->timestamp('received_at');
            $table->string('sender')->nullable();
            $table->string('subject')->nullable();
            $table->string('kind', 16)->nullable();
            $table->string('parser', 16)->nullable();
            $table->string('outcome', 16);
            $table->string('error', 64)->nullable();
            $table->foreignId('candidate_id')->nullable()->constrained('candidates')->nullOnDelete();
            $table->foreignId('touchpoint_id')->nullable()->constrained('touchpoints')->nullOnDelete();
            $table->timestamps();
            $table->index('received_at');
        });

        // One sync run: counters, error code, the internalDate cursor (ms) reached.
        Schema::create('mail_sync_runs', function (Blueprint $table): void {
            $table->id();
            // manual | cron
            $table->string('trigger', 8);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('cursor_ms')->nullable();
            $table->jsonb('counts')->nullable();
            $table->string('error', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_sync_runs');
        Schema::dropIfExists('mail_messages');
        Schema::dropIfExists('unknown_senders');
        Schema::dropIfExists('sender_rules');
    }
};
