<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Modules\People\Enums\ChangeableField;
use Illuminate\Foundation\Http\FormRequest;

/** POST /me/employee/change-requests {changes: {phone?, personal_email?, address?, emergency_contact?}, comment?} */
final class SubmitChangeRequestRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Only whitelisted keys; anything else (hired_at, position_id, …) → 422.
            'changes' => ['required', 'array', 'min:1', 'array:'.implode(',', ChangeableField::values())],
            'changes.phone' => ['nullable', 'string', 'max:32'],
            'changes.personal_email' => ['nullable', 'email', 'max:255'],
            'changes.address' => ['nullable', 'string', 'max:1000'],
            'changes.emergency_contact' => ['nullable', 'string', 'max:1000'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string|null> */
    public function changes(): array
    {
        $changes = [];
        foreach ((array) $this->input('changes', []) as $key => $value) {
            $changes[(string) $key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return $changes;
    }

    public function comment(): ?string
    {
        return $this->filled('comment') ? $this->string('comment')->trim()->toString() : null;
    }
}
