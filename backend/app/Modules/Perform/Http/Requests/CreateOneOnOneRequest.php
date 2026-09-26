<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Requests;

use App\Modules\People\Models\Employee;
use App\Modules\Perform\Models\OneOnOneTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/perform/one-on-ones {employee_id, manager_employee_id? (admins; default — own employee), scheduled_at,
 * template_id?, agenda?: [{text}]}.
 */
final class CreateOneOnOneRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists(Employee::class, 'id')],
            'manager_employee_id' => ['nullable', 'integer', Rule::exists(Employee::class, 'id')],
            'scheduled_at' => ['required', 'date'],
            'template_id' => ['nullable', 'integer', Rule::exists(OneOnOneTemplate::class, 'id')],
            'agenda' => ['nullable', 'array', 'max:50'],
            'agenda.*.text' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array{employee_id: int, manager_employee_id?: int|null, scheduled_at: string, template_id?: int|null, agenda?: list<array<string, mixed>>|null} */
    public function payload(): array
    {
        /** @var array{employee_id: int, manager_employee_id?: int|null, scheduled_at: string, template_id?: int|null, agenda?: list<array<string, mixed>>|null} $data */
        $data = $this->validated();
        $data['employee_id'] = (int) $data['employee_id'];
        if (isset($data['manager_employee_id'])) {
            $data['manager_employee_id'] = (int) $data['manager_employee_id'];
        }
        if (isset($data['template_id'])) {
            $data['template_id'] = (int) $data['template_id'];
        }

        return $data;
    }
}
