<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** PATCH …/actions/{actionId} {done: bool}. */
final class ToggleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['done' => ['required', 'boolean']];
    }

    public function done(): bool
    {
        return $this->boolean('done');
    }
}
