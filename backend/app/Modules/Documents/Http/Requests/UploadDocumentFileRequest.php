<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * POST /api/documents/{document}/file (multipart "file"): pdf, png, jpg, docx up to 2 MB. The storage checks the
 * type again from the bytes (a renamed file is refused even if the extension looks right).
 */
final class UploadDocumentFileRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:2048', 'extensions:pdf,png,jpg,jpeg,docx']];
    }

    public function upload(): UploadedFile
    {
        $file = $this->file('file');
        assert($file instanceof UploadedFile);

        return $file;
    }
}
