<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Conversation lookups of the Channels module (touchpoints of a channel by meta.thread): replies and continuity of threads.
 * MySQL 8.4 functional key part (PROD-50, ADR 0011). JSON/TEXT cannot be indexed directly, so the key is
 * cast(... as char(255)) collate utf8mb4_bin. The optimizer matches it with Laravel's where('meta->thread', $x)
 * (json_unquote(json_extract(`meta`, '$."thread"')) — utf8mb4_bin as well), the JSON path is written exactly as Laravel
 * compiles it. Thread ids of all adapters are short (Gmail/Telegram/WhatsApp/telephony ids); a meta.thread longer than
 * 255 characters would be rejected by MySQL on insert. A functional index is a hidden column: information_schema.columns and the transfer schema check do not
 * see it. The migration name is unchanged on purpose: the migration version is already recorded in applied databases.
 */
return new class extends Migration
{
    private const string INDEX = 'touchpoints_channel_thread_index';

    public function up(): void
    {
        DB::statement('CREATE INDEX '.self::INDEX.' ON touchpoints (channel, (cast(json_unquote(json_extract(`meta`, \'$."thread"\')) as char(255)) collate utf8mb4_bin))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX '.self::INDEX.' ON touchpoints');
    }
};
