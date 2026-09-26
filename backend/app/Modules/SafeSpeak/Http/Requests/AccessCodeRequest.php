<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/safe-speak/public/follow-up (code) and /reply (code + body). The code travels in the body, never in the
 * URL (URLs end up in logs and browser history).
 */
final class AccessCodeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
            'body' => [$this->routeIs('safe-speak.public.reply') ? 'required' : 'prohibited', 'string', 'max:10000'],
        ];
    }

    public function code(): string
    {
        return $this->string('code')->toString();
    }

    public function body(): string
    {
        return $this->string('body')->toString();
    }
}
