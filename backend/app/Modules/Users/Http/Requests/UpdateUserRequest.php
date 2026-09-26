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
            // Superadmin is never assigned through the API: it comes only from SUPERADMIN_EMAIL (bootstrap).
            'role' => ['sometimes', 'required', Rule::in(UserRole::invitableValues())],
            'status' => ['sometimes', 'required', Rule::enum(UserStatus::class)],
            // Full replacement of the user's branches ([] = none). Only existing active branches.
            'branch_ids' => ['sometimes', 'present', 'array', 'max:200'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists(Branch::class, 'id')->where('status', DirectoryStatus::Active->value)],
            // Safe Speak handler (reads anonymous reports); only for superadmin/admin — checked by the service.
            'safe_speak_handler' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function role(): ?UserRole
    {
        return $this->enum('role', UserRole::class);
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
