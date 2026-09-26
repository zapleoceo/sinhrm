<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Conversation lookups of the Channels module (meta->>'thread' per channel): replies and continuity of threads.
// Expression index is Postgres-only; other drivers (SQLite in tests) skip it.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE INDEX IF NOT EXISTS touchpoints_channel_thread_index ON touchpoints (channel, (meta->>'thread'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS touchpoints_channel_thread_index');
        }
    }
};
