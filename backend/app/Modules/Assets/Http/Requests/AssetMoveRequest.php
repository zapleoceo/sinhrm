<?php

declare(strict_types=1);

namespace App\Modules\Assets\Http\Requests;

use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\People\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * POST /api/assets/{id}/assign {employee_id, date?, condition?}
 * POST /api/assets/{id}/return {date?, condition?, status? in_stock|repair|written_off (default in_stock)}
 */
final class AssetMoveRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $assign = $this->routeIs('assets.assign');

        return [
            'employee_id' => [$assign ? 'required' : 'prohibited', 'integer', Rule::exists(Employee::class, 'id')],
            'date' => ['sometimes', 'nullable', 'date'],
            'condition' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => [$assign ? 'prohibited' : 'sometimes', Rule::in(AssetStatus::returnValues())],
        ];
    }

    public function employeeId(): int
    {
        return $this->integer('employee_id');
    }

    public function movedOn(): ?Carbon
    {
        return $this->filled('date') ? Carbon::parse($this->string('date')->toString())->startOfDay() : null;
    }

    public function condition(): ?string
    {
        return $this->filled('condition') ? $this->string('condition')->toString() : null;
    }

    public function returnStatus(): AssetStatus
    {
        return $this->enum('status', AssetStatus::class) ?? AssetStatus::InStock;
    }
}
