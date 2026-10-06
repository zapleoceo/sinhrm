<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Optional colleague who takes over the work when the termination applies (task "Прийняти справи"). */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('handover_to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('handover_to_employee_id');
        });
    }
};
