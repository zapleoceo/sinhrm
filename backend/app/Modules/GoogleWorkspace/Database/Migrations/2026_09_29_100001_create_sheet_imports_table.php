<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A remembered Google Sheets import: column mapping + the last imported row for incremental re-sync.
        Schema::create('sheet_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('spreadsheet_id', 128);
            // Sheet (tab) title; '' = the first sheet.
            $table->string('sheet', 100)->default('');
            $table->jsonb('headers');
            // {field: column index (0-based)}
            $table->jsonb('mapping');
            // Last processed row number (1 = only the header so far).
            $table->unsignedInteger('last_row')->default(1);
            $table->boolean('auto_sync')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable();
            // {created, matched, skipped, applied, vacancy_unmatched, errors[{row, code}]} — no cell values.
            $table->jsonb('last_report')->nullable();
            $table->timestamps();
            $table->unique(['spreadsheet_id', 'sheet']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_imports');
    }
};
