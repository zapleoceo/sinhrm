<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Modules\Directory\Enums\DirectoryStatus;
use App\Modules\Directory\Models\Department;
use App\Modules\Directory\Models\Position;
use App\Modules\People\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /people/bulk {action: department|position|manager|export, ids[≤200], department_id?, position_id?, manager_id?} */
final class BulkEmployeesRequest extends FormRequest
{
    public const int MAX = 200;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $active = static fn (string $model) => Rule::exists($model, 'id')->where('status', DirectoryStatus::Active->value);

        return [
            'action' => ['required', Rule::in(['department', 'position', 'manager', 'export'])],
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'ids.*' => ['integer', 'min:1', 'distinct'],
            'department_id' => ['required_if:action,department', 'nullable', 'integer', $active(Department::class)],
            'position_id' => ['required_if:action,position', 'nullable', 'integer', $active(Position::class)],
            'manager_id' => ['required_if:action,manager', 'nullable', 'integer', Rule::exists(Employee::class, 'id')],
        ];
    }

    public function action(): string
    {
        return (string) $this->validated('action');
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_values(array_map('intval', (array) $this->validated('ids')));
    }

    /** @return array<string, int> the single attribute to set for department|position|manager */
    public function change(): array
    {
        $field = $this->action().'_id';

        return [$field => (int) $this->validated($field)];
    }
}
