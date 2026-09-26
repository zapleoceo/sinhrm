<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Modules\People\Enums\ChangeRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListChangeRequestsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(ChangeRequestStatus::class)],
            'employee_id' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    public function status(): ?ChangeRequestStatus
    {
        return $this->enum('status', ChangeRequestStatus::class);
    }

    public function employeeId(): ?int
    {
        return $this->filled('employee_id') ? $this->integer('employee_id') : null;
    }

    public function perPage(): int
    {
        return $this->integer('perPage', 50);
    }
}
