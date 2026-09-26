<?php

declare(strict_types=1);

namespace App\Modules\Desk\Http\Requests;

use App\Modules\Desk\Enums\CaseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /api/desk/cases (HR queue): ?status=&assignee_id=&category_id=&open=1. */
final class QueueRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(CaseStatus::class)],
            'assignee_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'open' => ['nullable', 'boolean'],
        ];
    }

    /** @return array{status?: string|null, assignee_id?: int|null, category_id?: int|null, open?: bool} */
    public function filter(): array
    {
        return [
            'status' => $this->enum('status', CaseStatus::class)?->value,
            'assignee_id' => $this->filled('assignee_id') ? $this->integer('assignee_id') : null,
            'category_id' => $this->filled('category_id') ? $this->integer('category_id') : null,
            'open' => $this->boolean('open'),
        ];
    }
}
