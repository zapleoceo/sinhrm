<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->timestamps();
        });

        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->string('inventory_number', 64)->unique();
            $table->string('serial', 120)->nullable();
            $table->string('name', 200);
            $table->foreignId('type_id')->nullable()->constrained('asset_types')->nullOnDelete();
            // in_stock | assigned | repair | written_off
            $table->string('status', 16)->default('in_stock');
            $table->decimal('cost', 12, 2)->nullable();
            $table->date('purchased_at')->nullable();
            $table->text('notes')->nullable();
            // Denormalized current holder (the open assignment) for fast lists and the offboarding step.
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'type_id']);
            $table->index('employee_id');
        });

        // Full history: who had the asset, from when to when, in what condition it left and came back.
        Schema::create('asset_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('assigned_at');
            $table->date('returned_at')->nullable();
            $table->string('condition_out', 255)->nullable();
            $table->string('condition_in', 255)->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['asset_id', 'returned_at']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_types');
    }
};
