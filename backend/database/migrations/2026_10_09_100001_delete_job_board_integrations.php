<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Work.ua, Robota.ua and Djinni integration cards removed: these job boards have no self-serve employer API.
 * Candidates from them arrive via MailAgent parsers and the browser extension. Deletes their integrations rows,
 * secrets and logs.
 */
return new class extends Migration
{
    private const array KEYS = ['work_ua', 'robota_ua', 'djinni'];

    public function up(): void
    {
        $ids = DB::table('integrations')->whereIn('key', self::KEYS)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        DB::table('integration_secrets')->whereIn('integration_id', $ids)->delete();
        DB::table('integration_logs')->whereIn('integration_id', $ids)->delete();
        DB::table('integrations')->whereIn('id', $ids)->delete();
    }

    public function down(): void
    {
        // Data deletion is not reversible; the definitions no longer exist, so nothing to restore.
    }
};
