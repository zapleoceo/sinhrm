<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-user saved reports: a builder spec (whitelisted keys only) or a catalog report with its filters.
        // Re-run under the owner's CURRENT scope, so a saved report never keeps access the user lost.
        Schema::create('saved_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            // builder | catalog
            $table->string('kind', 16);
            $table->jsonb('definition');
            $table->timestamps();
            $table->index(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
    }
};
