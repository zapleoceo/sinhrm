<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** PUT /api/workflows/templates/{id}/webhook-secret {secret: string (16..200) | null}. The value is never returned. */
final class WebhookSecretRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['secret' => ['present', 'nullable', 'string', 'min:16', 'max:200']];
    }

    public function secret(): ?string
    {
        $secret = $this->input('secret');

        return is_string($secret) ? $secret : null;
    }
}
