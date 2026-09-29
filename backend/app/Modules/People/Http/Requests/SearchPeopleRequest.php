<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Modules\People\Enums\PickerScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /api/people/search?q=&scope=&limit=&include_terminated= — the person picker. q: at least 2 characters. */
final class SearchPeopleRequest extends FormRequest
{
    public const int MAX_LIMIT = 50;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Query strings arrive as strings ("20", "1"): 'integer' / 'boolean' accept them.
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'scope' => ['nullable', Rule::enum(PickerScope::class)],
            'limit' => ['nullable', 'integer', 'between:1,'.self::MAX_LIMIT],
            'include_terminated' => ['nullable', 'boolean'],
        ];
    }

    public function term(): string
    {
        return $this->string('q')->trim()->toString();
    }

    public function scope(): PickerScope
    {
        return $this->enum('scope', PickerScope::class) ?? PickerScope::Employees;
    }

    public function limit(): int
    {
        return $this->integer('limit', 20);
    }

    public function includeTerminated(): bool
    {
        return $this->boolean('include_terminated');
    }
}
