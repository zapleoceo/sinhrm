<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Modules\Core\Http\Requests\Concerns\Paginates;
use App\Modules\People\DTO\EmployeeFilter;
use App\Modules\People\Enums\EmployeeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListPeopleRequest extends FormRequest
{
    use Paginates;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Query strings arrive as strings ("20"): 'integer' accepts numeric strings.
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'position_id' => ['nullable', 'integer', 'min:1'],
            'manager_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(EmployeeStatus::class)],
            'perPage' => $this->perPageRules(),
        ];
    }

    public function filter(): EmployeeFilter
    {
        $id = fn (string $key): ?int => $this->filled($key) ? $this->integer($key) : null;

        return new EmployeeFilter(
            q: $this->filled('q') ? $this->string('q')->trim()->toString() : null,
            branchId: $id('branch_id'),
            departmentId: $id('department_id'),
            positionId: $id('position_id'),
            status: $this->enum('status', EmployeeStatus::class),
            managerId: $id('manager_id'),
            perPage: $this->perPageOr(),
        );
    }
}
