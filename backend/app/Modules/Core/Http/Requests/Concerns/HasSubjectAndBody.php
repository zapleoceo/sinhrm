<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests\Concerns;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A message with a subject line and a free-text body (Desk cases, SafeSpeak reports): the same limits and the same
 * reading — the subject is trimmed, the body is kept as typed.
 *
 * @phpstan-require-extends FormRequest
 */
trait HasSubjectAndBody
{
    /** @return array{subject: list<string>, body: list<string>} */
    protected function subjectAndBodyRules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
        ];
    }

    public function subject(): string
    {
        return trim($this->string('subject')->toString());
    }

    public function body(): string
    {
        return $this->string('body')->toString();
    }
}
