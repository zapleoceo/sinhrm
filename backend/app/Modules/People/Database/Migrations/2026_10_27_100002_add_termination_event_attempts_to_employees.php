<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Failed deliveries of EmployeeTerminated in a row (0 = none): a stuck event gets a warning in the log. */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->unsignedSmallInteger('termination_event_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('termination_event_attempts');
        });
    }
};
