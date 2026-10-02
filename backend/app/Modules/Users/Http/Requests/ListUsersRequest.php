<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Requests;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Core\Http\Requests\Concerns\Paginates;
use App\Modules\Users\DTO\UserFilter;
use App\Modules\Users\Enums\UserSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class ListUsersRequest extends FormRequest
{
    use Paginates;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Query strings arrive as strings ("20"): 'integer' accepts numeric strings, the DTO casts.
        // sort/dir: closed lists (unknown column or direction → 422, like any other bad filter).
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'last_login_from' => ['nullable', 'date_format:Y-m-d'],
            'last_login_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:last_login_from'],
            'sort' => ['nullable', Rule::enum(UserSort::class)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => $this->perPageRules(100),
        ];
    }

    public function filter(): UserFilter
    {
        $day = fn (string $key): ?Carbon => $this->filled($key) ? Carbon::createFromFormat('Y-m-d', $this->string($key)->toString())?->startOfDay() : null;

        return new UserFilter(
            q: $this->filled('q') ? $this->string('q')->trim()->toString() : null,
            status: $this->enum('status', UserStatus::class),
            role: $this->enum('role', UserRole::class),
            perPage: $this->perPageOr(20),
            lastLoginFrom: $day('last_login_from'),
            // "to" is inclusive: everything before the next midnight.
            lastLoginTo: $day('last_login_to')?->addDay(),
            sort: $this->enum('sort', UserSort::class) ?? UserSort::Name,
            descending: $this->input('dir') === 'desc',
        );
    }
}
