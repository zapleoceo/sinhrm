<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** HR who entered an exit answer on the employee's behalf (null = the employee answered themself). */
    public function up(): void
    {
        Schema::table('survey_responses', function (Blueprint $table): void {
            $table->foreignId('entered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('entered_by_user_id');
        });
    }
};
