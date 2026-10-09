<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Directory column filters (table headers): department and position are filtered like branch and manager,
 * which already have indexes. Explicit indexes keep the filter columns indexed whether or not a foreign key exists.
 *
 * MySQL 8.4: the explicit index replaces the one InnoDB created implicitly for the foreign key, so down() cannot drop
 * it while the foreign key exists (error 1553). down() drops the foreign key, the index, and recreates the foreign key
 * exactly as create_people_tables did (its implicit index comes back with it).
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
            $table->dropForeign(['department_id']);
            $table->dropForeign(['position_id']);
            $table->dropIndex(['department_id']);
            $table->dropIndex(['position_id']);
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('position_id')->references('id')->on('positions')->nullOnDelete();
        });
    }
};
