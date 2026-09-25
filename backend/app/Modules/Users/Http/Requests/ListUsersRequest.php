<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Users\DTO\UserFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListUsersRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            // Query strings arrive as strings ("20"): 'integer' accepts numeric strings, the DTO casts.
            'perPage' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    public function filter(): UserFilter
    {
        return new UserFilter(
            q: $this->filled('q') ? $this->string('q')->trim()->toString() : null,
            status: $this->enum('status', UserStatus::class),
            role: $this->enum('role', UserRole::class),
            perPage: $this->integer('perPage', 20),
        );
    }
}
