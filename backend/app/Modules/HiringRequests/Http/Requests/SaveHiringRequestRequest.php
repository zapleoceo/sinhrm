<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Http\Requests;

use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Models\Branch;
use App\Modules\Directory\Models\Department;
use App\Modules\Directory\Models\Position;
use App\Modules\HiringRequests\Enums\HiringPriority;
use App\Modules\HiringRequests\Enums\HiringReason;
use App\Modules\People\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/hiring-requests (title, branch_id, reason required; submit=true sends it for approval right away) and
 * PATCH /api/hiring-requests/{id} (draft, partial). Who may create/edit — the service (403); business rules — 422.
 */
final class SaveHiringRequestRequest extends FormRequest
{
    private const array FIELDS = [
        'title', 'branch_id', 'department_id', 'position_id', 'headcount', 'reason', 'replaced_employee_id',
        'desired_start_date', 'salary_min', 'salary_max', 'currency', 'requirements', 'priority', 'extra',
    ];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $req = $creating ? 'required' : 'sometimes';
        $active = static fn (string $model) => Rule::exists($model, 'id')->where('status', DirectoryStatus::Active->value);

        return [
            'title' => [$req, 'required', 'string', 'max:255'],
            'branch_id' => [$req, 'required', 'integer', $active(Branch::class)],
            'department_id' => ['sometimes', 'nullable', 'integer', $active(Department::class)],
            'position_id' => ['sometimes', 'nullable', 'integer', $active(Position::class)],
            'headcount' => ['sometimes', 'integer', 'between:1,500'],
            'reason' => [$req, 'required', Rule::enum(HiringReason::class)],
            'replaced_employee_id' => ['sometimes', 'nullable', 'integer', Rule::exists(Employee::class, 'id')],
            'desired_start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'salary_min' => ['sometimes', 'nullable', 'numeric', 'between:0,99999999'],
            'salary_max' => ['sometimes', 'nullable', 'numeric', 'between:0,99999999'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'requirements' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'priority' => ['sometimes', Rule::enum(HiringPriority::class)],
            'extra' => ['sometimes', 'nullable', 'array', 'max:30'],
            'submit' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> only the fields sent */
    public function requestData(): array
    {
        $out = [];
        foreach (self::FIELDS as $key) {
            if ($this->has($key)) {
                $out[$key] = $this->input($key);
            }
        }
        foreach (['branch_id', 'department_id', 'position_id', 'headcount', 'replaced_employee_id'] as $int) {
            if (isset($out[$int]) && is_numeric($out[$int])) {
                $out[$int] = (int) $out[$int];
            }
        }
        if (isset($out['title']) && is_string($out['title'])) {
            $out['title'] = trim($out['title']);
        }
        if (isset($out['currency']) && is_string($out['currency'])) {
            $out['currency'] = mb_strtoupper($out['currency']);
        }

        return $out;
    }

    public function submitNow(): bool
    {
        return $this->boolean('submit');
    }
}
