<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** When the former employee's personal data was erased after offboarding (Privacy module). */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->timestamp('anonymized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('anonymized_at');
        });
    }
};
