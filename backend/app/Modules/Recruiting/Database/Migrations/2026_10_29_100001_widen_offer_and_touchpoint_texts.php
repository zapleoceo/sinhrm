<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The offer text is rendered from a Documents template (body up to 50 000 characters, LONGTEXT there) and is sent as
     * the body of an outbound touchpoint. In TEXT (65 535 bytes) a long Cyrillic template did not fit: MySQL strict mode
     * refused the insert (SQLSTATE 22001 → 500 on POST /api/applications/{id}/offer). Found by the MySQL 8.4 e2e run,
     * round 2. LONGTEXT like documents.content_md; data is kept (TEXT → LONGTEXT only widens).
     */
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->longText('content_md')->change();
        });
        Schema::table('touchpoints', function (Blueprint $table): void {
            $table->longText('body')->nullable()->change();
        });
        $this->recreateView();
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->text('content_md')->change();
        });
        Schema::table('touchpoints', function (Blueprint $table): void {
            $table->text('body')->nullable()->change();
        });
        $this->recreateView();
    }

    /** `unmatched_messages` is `SELECT * FROM touchpoints`: rebuilt with the table (docs/modules/recruiting.md). */
    private function recreateView(): void
    {
        DB::statement('DROP VIEW IF EXISTS unmatched_messages');
        DB::statement('CREATE VIEW unmatched_messages AS SELECT * FROM touchpoints WHERE candidate_id IS NULL');
    }
};
