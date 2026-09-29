<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vacancy categories: a company dictionary like the others. Created empty — filled only in the admin.
        Schema::create('vacancy_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // active | disabled (App\Modules\Directory\Enums\DirectoryStatus)
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->index(['status', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancy_categories');
    }
};
