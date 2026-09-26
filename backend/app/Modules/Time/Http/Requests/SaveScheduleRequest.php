<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Requests;

use App\Modules\Directory\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PUT /api/time/schedules {branch_id? (null = company default), days: [1..7], hours_per_day}. Admins. */
final class SaveScheduleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', Rule::exists(Branch::class, 'id')],
            'days' => ['present', 'array', 'max:7'],
            'days.*' => ['integer', 'between:1,7', 'distinct'],
            'hours_per_day' => ['required', 'numeric', 'between:0,24'],
        ];
    }

    public function branchId(): ?int
    {
        return $this->filled('branch_id') ? $this->integer('branch_id') : null;
    }

    /** @return list<int> */
    public function days(): array
    {
        $days = array_values(array_map('intval', (array) $this->input('days', [])));
        sort($days);

        return $days;
    }

    public function hoursPerDay(): float
    {
        return round((float) $this->input('hours_per_day'), 2);
    }
}
