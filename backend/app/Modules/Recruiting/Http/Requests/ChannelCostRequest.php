<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/acquisition-channels/{id}/costs — spend for a period (inclusive dates). */
final class ChannelCostRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'amount' => ['required', 'numeric', 'between:0,99999999'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    public function cost(): array
    {
        return [
            'period_start' => $this->string('period_start')->toString(),
            'period_end' => $this->string('period_end')->toString(),
            'amount' => round((float) $this->input('amount'), 2),
            'currency' => mb_strtoupper($this->string('currency', 'UAH')->toString()),
            'note' => $this->filled('note') ? $this->string('note')->toString() : null,
        ];
    }
}
