<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Http\Requests;

use App\Modules\MailAgent\Enums\ParserKey;
use App\Modules\MailAgent\Enums\SenderKind;
use App\Modules\MailAgent\Support\SenderPattern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/mail/rules {pattern, kind, parser?} and PATCH /api/mail/rules/{rule} (all optional).
 * pattern: "name@domain.tld" or "@domain.tld"; parser only matters for job_board (default generic).
 * Access: route gate manage-integrations (superadmin).
 */
final class SaveSenderRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('pattern'))) {
            $this->merge(['pattern' => SenderPattern::normalize($this->input('pattern'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'pattern' => [$required, 'string', 'max:255', 'regex:'.SenderPattern::REGEX],
            'kind' => [$required, Rule::in(SenderKind::values())],
            'parser' => ['sometimes', 'nullable', Rule::in(ParserKey::values())],
        ];
    }

    public function pattern(): ?string
    {
        return $this->has('pattern') ? $this->string('pattern')->toString() : null;
    }

    public function kind(): ?SenderKind
    {
        return $this->has('kind') ? SenderKind::from($this->string('kind')->toString()) : null;
    }

    public function parser(): ?ParserKey
    {
        return $this->filled('parser') ? ParserKey::from($this->string('parser')->toString()) : null;
    }
}
