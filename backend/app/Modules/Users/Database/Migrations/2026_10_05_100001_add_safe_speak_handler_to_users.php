<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Explicit opt-in: only admins with this flag read and answer anonymous Safe Speak reports.
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('safe_speak_handler')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('safe_speak_handler');
        });
    }
};
