<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Week selector of the Time API: ?week=Y-m-d (any day of the week; default today) and ?employee_id= (default: self).
 * Also the body of PUT /api/time/week (entries) and POST /api/time/week/submit.
 */
class WeekRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'week' => ['nullable', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function week(): Carbon
    {
        return $this->filled('week') ? Carbon::parse($this->string('week')->toString()) : Carbon::now();
    }

    public function employeeId(): ?int
    {
        return $this->filled('employee_id') ? $this->integer('employee_id') : null;
    }

    public function branchId(): ?int
    {
        return $this->filled('branch_id') ? $this->integer('branch_id') : null;
    }
}
