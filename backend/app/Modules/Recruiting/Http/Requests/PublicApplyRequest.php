<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Documents\Contracts\DocumentStorage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/public/vacancies/{slug}/apply (multipart): name, email, phone?, message?, cv? (PDF/DOC/DOCX up to 2 MB),
 * consent (accepted, Law of Ukraine 2297-VI), website (honeypot, must stay empty; checked in the controller).
 */
final class PublicApplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:5000'],
            'cv' => ['nullable', 'file', 'max:'.intdiv(DocumentStorage::MAX_BYTES, 1024), 'mimes:pdf,doc,docx'],
            'consent' => ['accepted'],
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function isSpam(): bool
    {
        return $this->filled('website');
    }
}
