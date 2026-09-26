<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/workflows/templates/{id}/steps/reorder {ids: [step ids in the new order]} — all ids, each once. */
final class ReorderStepsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'max:'.SaveWorkflowTemplateRequest::MAX_STEPS],
            'ids.*' => ['integer', 'distinct'],
        ];
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_values(array_map('intval', (array) $this->validated('ids')));
    }
}
