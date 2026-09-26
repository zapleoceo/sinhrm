<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests;

use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PUT /api/modules/{key}: the switch and the full list of allowed system roles. */
final class UpdateModuleSettingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'distinct', Rule::in(UserRole::values())],
        ];
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }

    /** @return list<string> allowed roles in canonical order; superadmin is always kept (no self lock-out) */
    public function roles(): array
    {
        $sent = array_map(strval(...), (array) $this->input('roles', []));
        $sent[] = UserRole::Superadmin->value;

        return array_values(array_filter(UserRole::values(), static fn (string $r): bool => in_array($r, $sent, true)));
    }
}
