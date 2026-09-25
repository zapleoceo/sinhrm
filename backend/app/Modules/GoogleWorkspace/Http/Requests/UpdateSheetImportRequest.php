<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Http\Requests;

use App\Modules\GoogleWorkspace\Enums\SheetField;
use Illuminate\Foundation\Http\FormRequest;

/** PATCH /api/google/sheets/imports/{sheetImport} {auto_sync?, mapping?}. Superadmin (route gate). */
final class UpdateSheetImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'auto_sync' => ['sometimes', 'boolean'],
            'mapping' => ['sometimes', 'array:'.implode(',', SheetField::values())],
            'mapping.*' => ['integer', 'min:0', 'max:25'],
        ];
    }

    /** @return array<string, mixed> */
    public function changes(): array
    {
        $changes = [];
        if ($this->has('auto_sync')) {
            $changes['auto_sync'] = $this->boolean('auto_sync');
        }
        if ($this->has('mapping')) {
            $mapping = [];
            foreach ((array) $this->input('mapping') as $field => $index) {
                if (is_string($field) && is_numeric($index)) {
                    $mapping[$field] = (int) $index;
                }
            }
            $changes['mapping'] = $mapping;
        }

        return $changes;
    }
}
