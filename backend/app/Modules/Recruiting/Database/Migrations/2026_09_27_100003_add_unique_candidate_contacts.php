<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Candidate dedupe backed by the DB: one candidate per normalized phone / e-mail / Telegram. Partial unique indexes
 * (Postgres and SQLite 3.8+) — many candidates may have no contact at all. Replaces the plain lookup indexes.
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
            DB::statement("DROP INDEX IF EXISTS {$plain}");
            DB::statement("CREATE UNIQUE INDEX candidates_{$column}_unique ON candidates ({$column}) WHERE {$column} IS NOT NULL");
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column => $plain) {
            DB::statement("DROP INDEX IF EXISTS candidates_{$column}_unique");
            DB::statement("CREATE INDEX {$plain} ON candidates ({$column})");
        }
    }
};
