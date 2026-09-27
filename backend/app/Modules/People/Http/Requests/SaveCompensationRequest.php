<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Modules\People\Models\EmployeeCompensation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /people/{employee}/compensation {amount, currency, period, effective_on, reason?} */
final class SaveCompensationRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'currency' => ['required', Rule::in(EmployeeCompensation::CURRENCIES)],
            'period' => ['required', Rule::in(EmployeeCompensation::PERIODS)],
            'effective_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
