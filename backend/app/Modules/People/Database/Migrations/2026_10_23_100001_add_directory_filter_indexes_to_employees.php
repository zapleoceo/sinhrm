<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Directory column filters (table headers): department and position are filtered like branch and manager,
 * which already have indexes. Postgres does not index foreign keys by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->index('department_id');
            $table->index('position_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex(['department_id']);
            $table->dropIndex(['position_id']);
        });
    }
};
