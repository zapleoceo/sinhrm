<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Compensation history (current = latest effective_on) and optional HR-only gender for the pay-gap report. */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('gender', 16)->nullable();
        });
        Schema::create('employee_compensations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('period', 8);
            $table->date('effective_on');
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_compensations');
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('gender');
        });
    }
};
