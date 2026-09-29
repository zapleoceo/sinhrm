<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Modules\People\Enums\PickerScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /api/people/lookup?ids[]=1&ids[]=2&scope= — id → name for the person picker (at most 100 ids). */
final class LookupPeopleRequest extends FormRequest
{
    public const int MAX_IDS = 100;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['required', 'integer', 'min:1'],
            'scope' => ['nullable', Rule::enum(PickerScope::class)],
        ];
    }

    /** @return list<int> */
    public function ids(): array
    {
        $raw = $this->input('ids', []);
        $ids = is_array($raw) ? array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $raw) : [];

        return array_values(array_unique($ids));
    }

    public function scope(): PickerScope
    {
        return $this->enum('scope', PickerScope::class) ?? PickerScope::Employees;
    }
}
