<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Company dictionaries (branch, city, department, job). Filled by hand; rows are never deleted,
        // only disabled. external_id is dropped later by 2026_10_07_100001_drop_directory_external_ids.
        foreach (['cities', 'departments', 'positions'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $this->dictionaryColumns($table);
            });
        }

        Schema::create('branches', function (Blueprint $table): void {
            $this->dictionaryColumns($table);
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
        });

        // Branch scoping of users: recruiter/viewer work only with their branches.
        Schema::create('branch_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'branch_id']);
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('cities');
    }

    private function dictionaryColumns(Blueprint $table): void
    {
        $table->id();
        $table->string('external_id', 64)->nullable()->unique();
        $table->string('name');
        // active | disabled (App\Modules\Directory\Enums\DirectoryStatus)
        $table->string('status', 16)->default('active');
        $table->timestamps();
        $table->index(['status', 'name']);
    }
};
