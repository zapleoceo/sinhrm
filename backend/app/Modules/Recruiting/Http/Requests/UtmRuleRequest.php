<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/acquisition-channels/{id}/utm-rules {utm_source?, utm_medium?, utm_campaign?, priority?} and
 * POST /api/acquisition-channels/resolve {utm_source?, utm_medium?, utm_campaign?} (preview).
 */
final class UtmRuleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'utm_source' => ['nullable', 'string', 'max:120'],
            'utm_medium' => ['nullable', 'string', 'max:120'],
            'utm_campaign' => ['nullable', 'string', 'max:120'],
            'priority' => ['sometimes', 'integer', 'between:1,1000'],
        ];
    }

    /** @return array{utm_source: string|null, utm_medium: string|null, utm_campaign: string|null, priority: int} */
    public function rule(): array
    {
        $v = fn (string $key): ?string => $this->filled($key) ? $this->string($key)->toString() : null;

        return [
            'utm_source' => $v('utm_source'),
            'utm_medium' => $v('utm_medium'),
            'utm_campaign' => $v('utm_campaign'),
            'priority' => $this->integer('priority', 100),
        ];
    }
}
