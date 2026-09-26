<?php

declare(strict_types=1);

namespace App\Modules\Desk\Http\Requests;

use App\Modules\Desk\Enums\CaseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PATCH /api/desk/cases/{id}: status, assignee_id (null = unassign), category_id. Who may change what — the service. */
final class UpdateCaseRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', Rule::enum(CaseStatus::class)],
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
            'category_id' => ['sometimes', 'required', 'integer'],
        ];
    }

    /** @return array{status?: CaseStatus|null, assignee_id?: int|null, category_id?: int|null, unassign?: bool} */
    public function changes(): array
    {
        $changes = [];
        if ($this->has('status')) {
            $changes['status'] = $this->enum('status', CaseStatus::class);
        }
        if ($this->has('assignee_id')) {
            $id = $this->input('assignee_id');
            $id === null ? $changes['unassign'] = true : $changes['assignee_id'] = (int) $id;
        }
        if ($this->has('category_id')) {
            $changes['category_id'] = $this->integer('category_id');
        }

        return $changes;
    }
}
