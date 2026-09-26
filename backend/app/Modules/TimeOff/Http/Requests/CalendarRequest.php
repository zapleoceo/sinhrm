<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/** GET /timeoff/calendar?from&to&branch_id — defaults to the current month. */
final class CalendarRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function from(): Carbon
    {
        return $this->filled('from')
            ? (Carbon::createFromFormat('Y-m-d', $this->string('from')->toString())?->startOfDay() ?? Carbon::today())
            : Carbon::today()->startOfMonth();
    }

    public function to(): Carbon
    {
        return $this->filled('to')
            ? (Carbon::createFromFormat('Y-m-d', $this->string('to')->toString())?->startOfDay() ?? Carbon::today())
            : $this->from()->copy()->endOfMonth()->startOfDay();
    }

    public function branchId(): ?int
    {
        return $this->filled('branch_id') ? $this->integer('branch_id') : null;
    }
}
