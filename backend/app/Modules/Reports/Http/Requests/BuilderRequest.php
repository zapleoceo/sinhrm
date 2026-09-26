<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/reports/builder/run|csv. Only the shape is checked here; the whitelist (dataset, columns, PII,
 * operators, aggregates) is enforced by BuilderService — unknown keys are a 422, never SQL.
 */
final class BuilderRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dataset' => ['required', 'string', 'max:40'],
            'columns' => ['sometimes', 'array', 'max:20'],
            'columns.*' => ['string', 'max:40'],
            'filters' => ['sometimes', 'array', 'max:10'],
            'filters.*' => ['array'],
            'filters.*.column' => ['required', 'string', 'max:40'],
            'filters.*.op' => ['required', 'string', 'max:10'],
            'group_by' => ['sometimes', 'nullable', 'string', 'max:40'],
            'aggregate' => ['sometimes', 'nullable', 'array'],
            'aggregate.fn' => ['sometimes', 'string', 'max:10'],
            'aggregate.column' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }

    /** @return array<string, mixed> */
    public function spec(): array
    {
        /** @var array<string, mixed> $all */
        $all = $this->only(['dataset', 'columns', 'filters', 'group_by', 'aggregate']);

        return $all;
    }
}
