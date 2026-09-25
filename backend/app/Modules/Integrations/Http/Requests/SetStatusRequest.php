<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Requests;

use App\Modules\Integrations\Enums\IntegrationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SetStatusRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // connected/error are set only by a connection check.
        return ['status' => ['required', Rule::in(IntegrationStatus::manualValues())]];
    }

    public function status(): IntegrationStatus
    {
        return IntegrationStatus::from($this->string('status')->toString());
    }
}
