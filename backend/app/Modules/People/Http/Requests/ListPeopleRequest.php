<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Modules\People\DTO\EmployeeFilter;
use App\Modules\People\Enums\EmployeeSort;
use App\Modules\People\Enums\EmployeeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListPeopleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Query strings arrive as strings ("20"): 'integer' accepts numeric strings.
        // sort/dir: closed lists (unknown column or direction → 422, like any other bad filter).
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:100'],
            'contact' => ['nullable', 'string', 'max:100'],
            'manager' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'position_id' => ['nullable', 'integer', 'min:1'],
            'manager_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(EmployeeStatus::class)],
            'sort' => ['nullable', Rule::enum(EmployeeSort::class)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'perPage' => ['nullable', 'integer', 'between:1,200'],
        ];
    }

    public function filter(): EmployeeFilter
    {
        $id = fn (string $key): ?int => $this->filled($key) ? $this->integer($key) : null;
        $text = fn (string $key): ?string => $this->filled($key) ? $this->string($key)->trim()->toString() : null;

        return new EmployeeFilter(
            q: $text('q'),
            branchId: $id('branch_id'),
            departmentId: $id('department_id'),
            positionId: $id('position_id'),
            status: $this->enum('status', EmployeeStatus::class),
            managerId: $id('manager_id'),
            perPage: $this->integer('perPage', 50),
            name: $text('name'),
            contact: $text('contact'),
            manager: $text('manager'),
            sort: $this->enum('sort', EmployeeSort::class) ?? EmployeeSort::Name,
            descending: $this->input('dir') === 'desc',
        );
    }
}
