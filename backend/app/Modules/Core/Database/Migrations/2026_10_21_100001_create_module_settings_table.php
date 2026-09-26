<?php

declare(strict_types=1);

use App\Modules\Core\Services\ModuleRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Company-wide module switches (docs/modules/modules-access.md). Switching a module off never deletes
        // its data. A module without a row uses its defaults (enabled, roles = who can use it today).
        Schema::create('module_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('module', 64)->unique();
            $table->boolean('enabled')->default(true);
            $table->jsonb('roles');
            $table->timestamps();
        });

        // Seed the defaults of every non-core module, so the admin page shows real rows from day one.
        $now = now();
        foreach (app(ModuleRegistry::class)->all() as $module) {
            if (! $module->core) {
                DB::table('module_settings')->insert([
                    'module' => $module->key,
                    'enabled' => true,
                    'roles' => json_encode($module->defaultRoles, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('module_settings');
    }
};
