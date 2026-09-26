<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Anonymous reports. Deliberately NO user id, NO employee id, NO IP / user agent and NO time of day:
        // only the date, so the row cannot be joined to a login, a session or an access log.
        Schema::create('safe_speak_reports', function (Blueprint $table): void {
            $table->id();
            // HMAC-SHA256 (APP_KEY) of the normalized access code; the code itself is shown to the reporter once.
            $table->string('access_code_hash', 64)->unique();
            $table->string('category', 32);
            $table->string('subject', 200);
            // new | in_review | closed
            $table->string('status', 16)->default('new');
            $table->date('created_on');
            $table->date('updated_on');
        });

        // Thread: the reporter's messages (from = reporter, no identity) and handler replies (handler_id kept for audit,
        // never shown to the reporter). Date only, ordered by id.
        Schema::create('safe_speak_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('safe_speak_reports')->cascadeOnDelete();
            $table->string('author', 16);
            $table->foreignId('handler_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->date('created_on');
            $table->index(['report_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_speak_messages');
        Schema::dropIfExists('safe_speak_reports');
    }
};
