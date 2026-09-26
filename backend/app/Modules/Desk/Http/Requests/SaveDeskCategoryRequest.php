<?php

declare(strict_types=1);

namespace App\Modules\Desk\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST (all required keys) / PATCH (partial) /api/desk/categories. SLA hours: 1..2160 (90 days) or null. */
final class SaveDeskCategoryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'first_response_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2160'],
            'resolve_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2160'],
            'default_assignee_id' => ['sometimes', 'nullable', 'integer'],
            'active' => ['sometimes', 'boolean'],
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
