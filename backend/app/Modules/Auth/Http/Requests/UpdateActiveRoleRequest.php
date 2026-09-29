<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PUT /api/auth/active-role — {role: one of the user's ASSIGNED roles | null = all roles}. */
final class UpdateActiveRoleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->user();
        $assigned = $user instanceof User ? $user->assignedRoles() : [];

        return ['role' => ['present', 'nullable', 'string', Rule::in(count($assigned) > 1 ? $assigned : [])]];
    }

    public function role(): ?string
    {
        $role = $this->input('role');

        return is_string($role) ? $role : null;
    }
}
