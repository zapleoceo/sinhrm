<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Requests;

use App\Modules\Perform\Models\RatingScale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST/PUT /api/perform/review/competencies {name, description?, scale_id, active?}. Admins. */
final class SaveCompetencyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scale_id' => ['required', 'integer', Rule::exists(RatingScale::class, 'id')],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{name: string, description: string|null, scale_id: int, active: bool} */
    public function payload(): array
    {
        return [
            'name' => (string) $this->string('name'),
            'description' => $this->filled('description') ? (string) $this->string('description') : null,
            'scale_id' => $this->integer('scale_id'),
            'active' => $this->boolean('active', true),
        ];
    }
}
