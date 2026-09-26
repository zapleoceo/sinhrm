<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/** POST /people/{employee}/terminate {fired_at, reason?} */
final class TerminateEmployeeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fired_at' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function firedAt(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('fired_at')->toString())?->startOfDay() ?? Carbon::today();
    }

    public function reason(): ?string
    {
        return $this->filled('reason') ? $this->string('reason')->trim()->toString() : null;
    }
}
