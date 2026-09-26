<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /api/pulse/mood/me?days=30, /api/pulse/mood/team?weeks=8&branch_id=&department_id= (filters: admins). */
final class MoodReportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'days' => ['nullable', 'integer', 'between:1,366'],
            'weeks' => ['nullable', 'integer', 'between:1,26'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function days(): int
    {
        return $this->integer('days', 30);
    }

    public function weeks(): int
    {
        return $this->integer('weeks', 8);
    }

    public function branchId(): ?int
    {
        return $this->filled('branch_id') ? $this->integer('branch_id') : null;
    }

    public function departmentId(): ?int
    {
        return $this->filled('department_id') ? $this->integer('department_id') : null;
    }
}
