<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests;

use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InviteUserRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'role' => ['required', 'string', Rule::in(UserRole::invitableValues())],
        ];
    }

    public function email(): string
    {
        return mb_strtolower($this->string('email')->trim()->toString());
    }

    public function name(): string
    {
        return $this->string('name')->trim()->toString();
    }

    public function role(): UserRole
    {
        return UserRole::from($this->string('role')->toString());
    }
}
