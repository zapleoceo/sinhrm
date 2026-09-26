<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/privacy/{type}/{id}/erase {reason, confirm: true}. Irreversible: the reason is required (journal) and
 * the client must send confirm=true after the warning dialog.
 */
final class ErasePersonalDataRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirm' => ['required', 'accepted'],
        ];
    }

    public function reason(): string
    {
        return trim($this->string('reason')->toString());
    }
}
