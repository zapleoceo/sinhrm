<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dictionaries are maintained by hand only: the external import key is no longer used. */
return new class extends Migration
{
    private const array TABLES = ['branches', 'cities', 'departments', 'positions'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropUnique($name.'_external_id_unique');
            });
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('external_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('external_id', 64)->nullable()->unique()->after('id');
            });
        }
    }
};
