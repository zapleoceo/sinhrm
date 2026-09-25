<?php

declare(strict_types=1);

namespace App\Modules\Directory\Http\Requests;

use App\Modules\Directory\DTO\DictionaryItemData;
use App\Modules\Directory\Enums\DictionaryType;
use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Models\City;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST: name required) and edit (PATCH: every field optional) of a dictionary item.
 * city_id is accepted for branches only. external_id is set by the importer, never through the API.
 */
final class SaveDictionaryItemRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'min:1', 'max:255'],
            'status' => ['sometimes', 'required', Rule::enum(DirectoryStatus::class)],
            'city_id' => $this->dictionary() === DictionaryType::Branches
                ? ['sometimes', 'nullable', 'integer', Rule::exists(City::class, 'id')->where('status', DirectoryStatus::Active->value)]
                : ['prohibited'],
        ];
    }

    public function itemData(): DictionaryItemData
    {
        return new DictionaryItemData(
            name: $this->filled('name') ? $this->string('name')->trim()->toString() : null,
            status: $this->enum('status', DirectoryStatus::class),
            cityId: $this->filled('city_id') ? $this->integer('city_id') : null,
            cityIdSent: $this->has('city_id'),
        );
    }

    private function dictionary(): ?DictionaryType
    {
        $type = $this->route('dictionary');

        return $type instanceof DictionaryType ? $type : DictionaryType::tryFrom(is_string($type) ? $type : '');
    }
}
