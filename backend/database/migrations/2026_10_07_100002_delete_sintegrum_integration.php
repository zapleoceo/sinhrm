<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Sintegrum integration removed (owner decision): deletes its integrations row; its secrets and logs are removed explicitly too. */
return new class extends Migration
{
    private const string KEY = 'sintegrum_api';

    public function up(): void
    {
        $ids = DB::table('integrations')->where('key', self::KEY)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        DB::table('integration_secrets')->whereIn('integration_id', $ids)->delete();
        DB::table('integration_logs')->whereIn('integration_id', $ids)->delete();
        DB::table('integrations')->whereIn('id', $ids)->delete();
    }

    public function down(): void
    {
        // Data deletion is not reversible.
    }
};
