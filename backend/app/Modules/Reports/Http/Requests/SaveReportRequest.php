<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use App\Modules\Reports\Models\SavedReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST/PUT /api/reports/saved: {name, kind: builder|catalog, definition: {...}} */
final class SaveReportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in([SavedReport::BUILDER, SavedReport::CATALOG])],
            'definition' => ['required', 'array'],
        ];
    }

    public function name(): string
    {
        return trim($this->string('name')->toString());
    }

    public function kind(): string
    {
        return $this->string('kind')->toString();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        /** @var array<string, mixed> $definition */
        $definition = (array) $this->input('definition');

        return $definition;
    }
}
