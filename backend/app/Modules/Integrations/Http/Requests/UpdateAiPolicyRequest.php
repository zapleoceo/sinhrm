<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAiPolicyRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['enabled' => ['required', 'boolean']];
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }
}
