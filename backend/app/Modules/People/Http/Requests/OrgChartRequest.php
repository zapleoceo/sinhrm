<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /people/org-chart?branch_id&root_id&mine=1 */
final class OrgChartRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'root_id' => ['nullable', 'integer', 'min:1'],
            'mine' => ['nullable', 'boolean'],
        ];
    }

    public function branchId(): ?int
    {
        return $this->filled('branch_id') ? $this->integer('branch_id') : null;
    }

    public function rootId(): ?int
    {
        return $this->filled('root_id') ? $this->integer('root_id') : null;
    }

    public function mine(): bool
    {
        return $this->boolean('mine');
    }
}
