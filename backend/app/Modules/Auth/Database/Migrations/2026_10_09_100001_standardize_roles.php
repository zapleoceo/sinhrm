<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data migration: the standard role set (docs/modules/auth.md, section "Ролі"). Existing roles (superadmin, admin,
 * recruiter, viewer) keep their names and holders. Adds hr_manager and employee; a user without any role becomes an
 * employee (self-service). Role names are literals on purpose: a migration must not follow later enum edits.
 *
 * Rollback: hr_manager holders fall back to admin (the role that acted as HR before), employee assignments are
 * removed, then both roles are deleted.
 */
return new class extends Migration
{
    private const string GUARD = 'web';

    private const string USER_TYPE = 'App\Models\User';

    public function up(): void
    {
        Role::findOrCreate('hr_manager', self::GUARD);
        $employee = Role::findOrCreate('employee', self::GUARD);

        $withoutRole = DB::table('users')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('model_has_roles')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('model_has_roles.model_type', self::USER_TYPE))
            ->pluck('id');
        foreach ($withoutRole as $id) {
            DB::table('model_has_roles')->insert(['role_id' => $employee->id, 'model_type' => self::USER_TYPE, 'model_id' => $id]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $roles = Role::query()->whereIn('name', ['hr_manager', 'employee'])->where('guard_name', self::GUARD)->pluck('id', 'name');
        $admin = Role::query()->where('name', 'admin')->where('guard_name', self::GUARD)->value('id');
        if (isset($roles['hr_manager']) && $admin !== null) {
            $holders = DB::table('model_has_roles')->where('role_id', $roles['hr_manager'])->where('model_type', self::USER_TYPE)->pluck('model_id');
            foreach ($holders as $id) {
                DB::table('model_has_roles')->insertOrIgnore(['role_id' => $admin, 'model_type' => self::USER_TYPE, 'model_id' => $id]);
            }
        }
        DB::table('model_has_roles')->whereIn('role_id', $roles->values())->delete();
        Role::query()->whereIn('id', $roles->values())->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
