<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

use Illuminate\Support\Carbon;

/** Inclusive date range of a report: [from 00:00:00, to 23:59:59]. */
final readonly class DateRange
{
    public function __construct(
        public Carbon $from,
        public Carbon $to,
    ) {}

    public static function ofDays(?string $from, ?string $to, int $defaultDays = 30): self
    {
        $end = $to !== null ? Carbon::parse($to)->endOfDay() : Carbon::now()->endOfDay();
        $start = $from !== null ? Carbon::parse($from)->startOfDay() : $end->copy()->subDays($defaultDays - 1)->startOfDay();

        return new self($start, $end);
    }

    /** @return array{from: string, to: string} */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }
}
