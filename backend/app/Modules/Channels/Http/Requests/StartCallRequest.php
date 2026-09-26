<?php

declare(strict_types=1);

namespace App\Modules\Channels\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /candidates/{candidate}/call — click-to-call, same right as logging a touch. */
final class StartCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('candidate'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
