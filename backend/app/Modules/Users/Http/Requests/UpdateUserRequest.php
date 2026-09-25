<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUserRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', 'required', Rule::enum(UserRole::class)],
            'status' => ['sometimes', 'required', Rule::enum(UserStatus::class)],
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
}
