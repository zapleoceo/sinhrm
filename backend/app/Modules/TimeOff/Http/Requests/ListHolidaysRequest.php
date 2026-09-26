<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /timeoff/holidays?year&branch_id (strings from the query are fine). */
final class ListHolidaysRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function year(): ?int
    {
        return $this->filled('year') ? $this->integer('year') : null;
    }

    public function branchId(): ?int
    {
        return $this->filled('branch_id') ? $this->integer('branch_id') : null;
    }
}
