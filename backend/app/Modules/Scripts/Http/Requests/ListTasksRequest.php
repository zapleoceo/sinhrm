<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Http\Requests;

use App\Modules\Scripts\DTO\TaskFilter;
use App\Modules\Scripts\Enums\TaskDue;
use App\Modules\Scripts\Enums\TaskSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/tasks?mine=1&due=today|overdue&candidate_id=&done=1&source=recruiting|workflows|documents&employee_id=
 * (strings are fine: "1").
 */
final class ListTasksRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mine' => ['nullable', 'boolean'],
            'due' => ['nullable', Rule::enum(TaskDue::class)],
            'candidate_id' => ['nullable', 'integer', 'min:1'],
            'done' => ['nullable', 'boolean'],
            'source' => ['nullable', Rule::enum(TaskSource::class)],
            'employee_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filter(): TaskFilter
    {
        return new TaskFilter(
            mine: $this->boolean('mine'),
            due: $this->enum('due', TaskDue::class),
            candidateId: $this->filled('candidate_id') ? $this->integer('candidate_id') : null,
            withDone: $this->boolean('done'),
            source: $this->enum('source', TaskSource::class),
            employeeId: $this->filled('employee_id') ? $this->integer('employee_id') : null,
        );
    }
}
