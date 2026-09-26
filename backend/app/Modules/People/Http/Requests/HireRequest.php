<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/** POST /applications/{application}/hire {hired_at?} — whoever may move the application (ApplicationPolicy::move). */
final class HireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('move', $this->route('application'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['hired_at' => ['nullable', 'date_format:Y-m-d']];
    }

    public function hiredAt(): Carbon
    {
        return $this->filled('hired_at')
            ? (Carbon::createFromFormat('Y-m-d', $this->string('hired_at')->toString())?->startOfDay() ?? Carbon::today())
            : Carbon::today();
    }
}
