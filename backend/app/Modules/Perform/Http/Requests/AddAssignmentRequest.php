<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Requests;

use App\Modules\People\Models\Employee;
use App\Modules\Perform\Enums\ReviewType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/perform/review/cycles/{id}/assignments {subject_employee_id, reviewer_employee_id, type}. Admins. */
final class AddAssignmentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject_employee_id' => ['required', 'integer', Rule::exists(Employee::class, 'id')],
            'reviewer_employee_id' => ['required', 'integer', Rule::exists(Employee::class, 'id')],
            'type' => ['required', Rule::enum(ReviewType::class)],
        ];
    }

    public function subjectId(): int
    {
        return $this->integer('subject_employee_id');
    }

    public function reviewerId(): int
    {
        return $this->integer('reviewer_employee_id');
    }

    public function type(): ReviewType
    {
        return ReviewType::from((string) $this->string('type'));
    }
}
