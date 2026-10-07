<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candidate dedupe backed by the DB: one candidate per normalized phone / e-mail / Telegram. SQL unique indexes
 * permit multiple NULL contacts on PostgreSQL and MySQL. Replaces the plain lookup indexes.
 * CandidateService maps a violation (concurrent create) to the same 409 duplicate_candidate.
 */
return new class extends Migration
{
    /** @var array<string, string> column => plain index created by 2026_09_27_100001 */
    private const array COLUMNS = [
        'phone' => 'candidates_phone_index',
        'email' => 'candidates_email_index',
        'telegram_username' => 'candidates_telegram_username_index',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $column => $plain) {
            Schema::table('candidates', function (Blueprint $table) use ($column, $plain): void {
                $table->dropIndex($plain);
                $table->unique($column, "candidates_{$column}_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column => $plain) {
            Schema::table('candidates', function (Blueprint $table) use ($column, $plain): void {
                $table->dropUnique("candidates_{$column}_unique");
                $table->index($column, $plain);
            });
        }
    }
};
