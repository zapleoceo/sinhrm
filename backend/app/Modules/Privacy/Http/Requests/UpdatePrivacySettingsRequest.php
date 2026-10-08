<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** PUT /api/privacy/settings {retention_rejected_months: 1..120 | null (off)}; the key is required, null switches it off. */
final class UpdatePrivacySettingsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'retention_rejected_months' => ['present', 'nullable', 'integer', 'min:1', 'max:120'],
        ];
    }

    public function retentionRejectedMonths(): ?int
    {
        return $this->input('retention_rejected_months') === null ? null : $this->integer('retention_rejected_months');
    }
}
