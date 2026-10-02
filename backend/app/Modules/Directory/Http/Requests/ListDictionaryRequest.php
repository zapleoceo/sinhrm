<?php

declare(strict_types=1);

namespace App\Modules\Directory\Http\Requests;

use App\Modules\Directory\DTO\DictionaryFilter;
use App\Modules\Directory\Enums\DictionarySort;
use App\Modules\Directory\Enums\DictionaryType;
use App\Modules\Directory\Enums\DirectoryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListDictionaryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // City (sort and filter) exists on branches only: on other dictionaries it is an unknown column → 422.
        $sorts = $this->isBranches()
            ? DictionarySort::cases()
            : array_values(array_filter(DictionarySort::cases(), static fn (DictionarySort $s): bool => $s !== DictionarySort::City));

        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(DirectoryStatus::class)],
            'city_id' => $this->isBranches() ? ['nullable', 'integer', 'min:1'] : ['prohibited'],
            'sort' => ['nullable', Rule::enum(DictionarySort::class)->only($sorts)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            // Query strings arrive as strings ("50"): 'integer' accepts numeric strings, the DTO casts.
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    public function filter(): DictionaryFilter
    {
        return new DictionaryFilter(
            q: $this->filled('q') ? $this->string('q')->trim()->toString() : null,
            status: $this->enum('status', DirectoryStatus::class),
            perPage: $this->integer('perPage', 50),
            cityId: $this->filled('city_id') ? $this->integer('city_id') : null,
            sort: $this->enum('sort', DictionarySort::class) ?? DictionarySort::Name,
            descending: $this->input('dir') === 'desc',
        );
    }

    private function isBranches(): bool
    {
        $type = $this->route('dictionary');

        return $type === DictionaryType::Branches || $type === DictionaryType::Branches->value;
    }
}
