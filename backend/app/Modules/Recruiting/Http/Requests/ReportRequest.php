<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Http\Requests;

use App\Modules\Recruiting\DTO\DateRange;
use Illuminate\Foundation\Http\FormRequest;

/** ?from=YYYY-MM-DD&to=YYYY-MM-DD (default: last 30 days), funnel also ?vacancy_id=. Range at most a year. */
final class ReportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'vacancy_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function range(): DateRange
    {
        $range = DateRange::ofDays(
            $this->filled('from') ? $this->string('from')->toString() : null,
            $this->filled('to') ? $this->string('to')->toString() : null,
        );
        if ($range->from->diffInDays($range->to) > 366) {
            return new DateRange($range->to->copy()->subDays(365)->startOfDay(), $range->to);
        }

        return $range;
    }

    public function vacancyId(): ?int
    {
        return $this->filled('vacancy_id') ? $this->integer('vacancy_id') : null;
    }
}
