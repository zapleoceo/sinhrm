<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Http\Requests;

use App\Modules\MailAgent\Enums\ParserKey;
use App\Modules\MailAgent\Enums\SenderKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/mail/unknown-senders/{sender}/assign {kind, parser?, scope?: email|domain}. Superadmin (route gate). */
final class AssignSenderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(SenderKind::values())],
            'parser' => ['sometimes', 'nullable', Rule::in(ParserKey::values())],
            'scope' => ['sometimes', Rule::in(['email', 'domain'])],
        ];
    }

    public function kind(): SenderKind
    {
        return SenderKind::from($this->string('kind')->toString());
    }

    public function parser(): ?ParserKey
    {
        return $this->filled('parser') ? ParserKey::from($this->string('parser')->toString()) : null;
    }

    public function wholeDomain(): bool
    {
        return $this->input('scope') === 'domain';
    }
}
