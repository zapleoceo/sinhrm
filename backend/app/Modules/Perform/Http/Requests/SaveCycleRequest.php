<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Requests;

use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\Department;
use App\Modules\Perform\Enums\ReviewType;
use App\Modules\Perform\Models\Competency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST/PUT /api/perform/review/cycles (draft only) {name, period_start, period_end, participants: {branch_ids, department_ids},
 * types: [self|manager|peer|upward], competency_ids (1..30), anonymous, deadlines: {type: Y-m-d}}. Admins.
 */
final class SaveCycleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'participants' => ['present', 'array'],
            'participants.branch_ids' => ['sometimes', 'array'],
            'participants.branch_ids.*' => ['integer', Rule::exists(Branch::class, 'id')],
            'participants.department_ids' => ['sometimes', 'array'],
            'participants.department_ids.*' => ['integer', Rule::exists(Department::class, 'id')],
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['distinct', Rule::enum(ReviewType::class)],
            'competency_ids' => ['required', 'array', 'min:1', 'max:30'],
            'competency_ids.*' => ['integer', 'distinct', Rule::exists(Competency::class, 'id')],
            'anonymous' => ['sometimes', 'boolean'],
            'deadlines' => ['sometimes', 'array'],
            'deadlines.*' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $types = array_values(array_map('strval', (array) $this->input('types')));
        $deadlines = array_filter(
            array_intersect_key((array) $this->input('deadlines', []), array_flip($types)),
            static fn (mixed $d): bool => is_string($d) && $d !== '',
        );

        return [
            'name' => (string) $this->string('name'),
            'period_start' => (string) $this->string('period_start'),
            'period_end' => (string) $this->string('period_end'),
            'participants' => [
                'branch_ids' => array_values(array_map('intval', (array) $this->input('participants.branch_ids', []))),
                'department_ids' => array_values(array_map('intval', (array) $this->input('participants.department_ids', []))),
            ],
            'types' => $types,
            'competency_ids' => array_values(array_map('intval', (array) $this->input('competency_ids'))),
            'anonymous' => $this->boolean('anonymous', true),
            'deadlines' => $deadlines,
        ];
    }
}
