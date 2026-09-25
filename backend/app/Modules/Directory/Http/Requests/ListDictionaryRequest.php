<?php

declare(strict_types=1);

namespace App\Modules\Directory\Http\Requests;

use App\Modules\Directory\DTO\DictionaryFilter;
use App\Modules\Directory\Enums\DirectoryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListDictionaryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(DirectoryStatus::class)],
            // Query strings arrive as strings ("50"): 'integer' accepts numeric strings, the DTO casts.
            'perPage' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    public function filter(): DictionaryFilter
    {
        return new DictionaryFilter(
            q: $this->filled('q') ? $this->string('q')->trim()->toString() : null,
            status: $this->enum('status', DirectoryStatus::class),
            perPage: $this->integer('perPage', 50),
        );
    }
}
