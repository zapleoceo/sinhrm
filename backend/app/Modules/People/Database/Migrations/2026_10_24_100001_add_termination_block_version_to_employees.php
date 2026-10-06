<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * users.credential_version right after the termination blocked the login (null = termination did not block it).
     * Restore unblocks the user only while it still matches: a later manual block changes the version.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->unsignedInteger('termination_block_version')->nullable();
            // EmployeeTerminated not delivered yet (a listener threw): the cron job re-sends it.
            $table->boolean('termination_event_pending')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['termination_block_version', 'termination_event_pending']);
        });
    }
};
