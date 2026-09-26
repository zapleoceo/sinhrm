<?php

declare(strict_types=1);

namespace App\Modules\Knowledge\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveKbCategoryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:120'],
            'emoji' => ['sometimes', 'nullable', 'string', 'max:16'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesToSave(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return $data;
    }
}
