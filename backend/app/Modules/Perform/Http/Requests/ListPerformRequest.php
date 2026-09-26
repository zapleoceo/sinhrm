<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Requests;

use App\Modules\Perform\Enums\OneOnOneStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shared list filters: ?employee_id=&period=&status=&owner_employee_id= (each list uses what it needs). */
final class ListPerformRequest extends FormRequest
{
    public const string PERIOD = '/^\d{4}-(Q[1-4]|0[1-9]|1[0-2])$/';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'owner_employee_id' => ['nullable', 'integer', 'min:1'],
            'period' => ['nullable', 'string', 'regex:'.self::PERIOD],
            'status' => ['nullable', Rule::enum(OneOnOneStatus::class)],
        ];
    }

    public function employeeId(): ?int
    {
        return $this->filled('employee_id') ? $this->integer('employee_id') : null;
    }

    public function ownerId(): ?int
    {
        return $this->filled('owner_employee_id') ? $this->integer('owner_employee_id') : null;
    }

    public function period(): ?string
    {
        return $this->filled('period') ? (string) $this->string('period') : null;
    }

    public function status(): ?string
    {
        return $this->filled('status') ? (string) $this->string('status') : null;
    }
}
