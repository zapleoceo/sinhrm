<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Http\Requests;

use App\Modules\MailAgent\Models\SenderRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /api/mail/rules?source=manual|ai — "created by AI" filter of the rules list. */
final class ListSenderRulesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['source' => ['nullable', 'string', Rule::in([SenderRule::SOURCE_MANUAL, SenderRule::SOURCE_AI])]];
    }

    public function source(): ?string
    {
        return $this->filled('source') ? $this->string('source')->toString() : null;
    }
}
