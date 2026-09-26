<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Approve / reject with an optional comment (People change requests, TimeOff leave requests). */
final class DecisionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['comment' => ['nullable', 'string', 'max:2000']];
    }

    public function comment(): ?string
    {
        return $this->filled('comment') ? $this->string('comment')->trim()->toString() : null;
    }
}
