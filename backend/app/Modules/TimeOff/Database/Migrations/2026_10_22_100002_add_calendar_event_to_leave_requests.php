<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Google Calendar event of an approved request (company calendar), deleted on cancel.
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->string('calendar_event_id', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropColumn('calendar_event_id');
        });
    }
};
