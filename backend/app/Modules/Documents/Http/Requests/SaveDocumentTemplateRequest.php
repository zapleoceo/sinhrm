<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/documents/templates (name + body required) and PATCH …/{template} (partial, archived).
 * The body is plain text / Markdown; HTML tags are allowed to be typed but are shown as text (escaped on render).
 */
final class SaveDocumentTemplateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'required', 'string', 'max:255'],
            'body' => [$req, 'required', 'string', 'max:50000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesToSave(): array
    {
        $data = $this->safe()->only(['name', 'body', 'category', 'archived']);
        if (array_key_exists('archived', $data)) {
            $data['archived'] = $this->boolean('archived');
        }

        return $data;
    }
}
