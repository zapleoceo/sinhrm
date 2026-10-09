<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /api/privacy/{type}/{id}/export?format=json|html (default json). */
final class ExportPersonalDataRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'format' => ['nullable', 'string', 'in:json,html'],
        ];
    }

    public function wantsHtml(): bool
    {
        return $this->query('format') === 'html';
    }
}
