<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The "last transition into the stage" lookup of the reject-reasons report: max(id) per (application, to_stage). */
    public function up(): void
    {
        Schema::table('stage_changes', function (Blueprint $table): void {
            $table->index(['application_id', 'to_stage_id'], 'stage_changes_application_to_stage_index');
        });
    }

    public function down(): void
    {
        Schema::table('stage_changes', function (Blueprint $table): void {
            $table->dropIndex('stage_changes_application_to_stage_index');
        });
    }
};
