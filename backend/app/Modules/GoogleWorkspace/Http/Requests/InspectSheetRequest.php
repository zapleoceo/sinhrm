<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Http\Requests;

use App\Modules\GoogleWorkspace\Enums\SheetField;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/google/sheets/inspect {url, sheet?} and (with mapping) POST /api/google/sheets/imports.
 * Access: route gate manage-integrations (superadmin). The URL must be docs.google.com/spreadsheets/d/<id>.
 */
final class InspectSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'url' => ['required', 'string', 'max:500', 'regex:#^https://docs\.google\.com/spreadsheets/d/[A-Za-z0-9_-]{20,128}#'],
            'sheet' => ['nullable', 'string', 'max:100'],
        ];
        if ($this->routeIs('google.sheets.imports.store')) {
            $rules['mapping'] = ['required', 'array:'.implode(',', SheetField::values())];
            $rules['mapping.*'] = ['integer', 'min:0', 'max:25'];
            $rules['auto_sync'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    public function url(): string
    {
        return trim($this->string('url')->toString());
    }

    public function sheet(): string
    {
        return trim($this->string('sheet')->toString());
    }

    /** @return array<string, int> */
    public function mapping(): array
    {
        $mapping = [];
        foreach ((array) $this->input('mapping', []) as $field => $index) {
            if (is_string($field) && is_numeric($index)) {
                $mapping[$field] = (int) $index;
            }
        }

        return $mapping;
    }
}
