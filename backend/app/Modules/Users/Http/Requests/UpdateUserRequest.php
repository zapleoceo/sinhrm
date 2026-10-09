<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUserRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Any global role, superadmin included (HRM-84). Giving or taking superadmin is re-checked by the service:
            // only an actor acting as superadmin (403 superadmin_forbidden), never the last active one (last_superadmin).
            'role' => ['sometimes', 'required', 'prohibits:roles', Rule::in(UserRole::values())],
            // Several global roles at once (full replacement, at least one).
            'roles' => ['sometimes', 'required', 'array', 'min:1', 'max:'.count(UserRole::cases())],
            'roles.*' => ['string', 'distinct', Rule::in(UserRole::values())],
            'status' => ['sometimes', 'required', Rule::enum(UserStatus::class)],
            // Full replacement of the user's branches ([] = none). Only existing active branches.
            'branch_ids' => ['sometimes', 'present', 'array', 'max:200'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists(Branch::class, 'id')->where('status', DirectoryStatus::Active->value)],
            // Safe Speak handler (reads anonymous reports); only for superadmin/admin — checked by the service.
            'safe_speak_handler' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * New global roles: `roles` (array) or the older single `role`; null = not sent (unchanged).
     *
     * @return list<UserRole>|null
     */
    public function roles(): ?array
    {
        if ($this->has('roles')) {
            return array_values(array_map(static fn (mixed $r): UserRole => UserRole::from((string) $r), (array) $this->input('roles')));
        }
        $role = $this->enum('role', UserRole::class);

        return $role === null ? null : [$role];
    }

    public function status(): ?UserStatus
    {
        return $this->enum('status', UserStatus::class);
    }

    /** null = not sent (unchanged) */
    public function safeSpeakHandler(): ?bool
    {
        return $this->has('safe_speak_handler') ? $this->boolean('safe_speak_handler') : null;
    }

    /** @return list<int>|null null = not sent (unchanged) */
    public function branchIds(): ?array
    {
        if (! $this->has('branch_ids')) {
            return null;
        }

        return array_values(array_map(intval(...), (array) $this->input('branch_ids', [])));
    }
}
