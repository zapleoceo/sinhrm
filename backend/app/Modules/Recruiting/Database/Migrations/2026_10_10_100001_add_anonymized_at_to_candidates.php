<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** When the candidate's personal data was erased on request or by the retention rule (Privacy module). */
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            $table->timestamp('anonymized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table): void {
            $table->dropColumn('anonymized_at');
        });
    }
};
