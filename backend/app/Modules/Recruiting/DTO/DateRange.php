<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

use App\Modules\Core\Support\UserTime;
use Illuminate\Support\Carbon;

/**
 * Inclusive date range of a report: [from 00:00:00, to 23:59:59] of the user's (Kyiv) calendar days.
 * $from / $to carry those days as dates (UserTime::today() convention: the day at midnight of the storage zone), so
 * date columns, months and labels compare as before. Timestamp columns (UTC moments) are compared with moments():
 * the user's day 00:00..23:59:59 in Kyiv, converted to UTC — an event at 22:30 UTC on Oct 10 is Oct 11 in Kyiv.
 */
final readonly class DateRange
{
    public function __construct(
        public Carbon $from,
        public Carbon $to,
    ) {}

    /** $from / $to are Y-m-d days of the user's zone; no $to → today in the user's zone (not the UTC date). */
    public static function ofDays(?string $from, ?string $to, int $defaultDays = 30): self
    {
        $end = ($to !== null ? Carbon::parse($to) : UserTime::today())->endOfDay();
        $start = $from !== null ? Carbon::parse($from)->startOfDay() : $end->copy()->subDays($defaultDays - 1)->startOfDay();

        return new self($start, $end);
    }

    /** The last $days days of the range (same end) when the range is longer, else the range itself. */
    public function lastDays(int $days): self
    {
        $first = $this->to->copy()->subDays($days - 1)->startOfDay();

        return $first->gt($this->from) ? new self($first, $this->to) : $this;
    }

    /** @return array{0: Carbon, 1: Carbon} the range as storage-zone (UTC) moments, for whereBetween on a timestamp column */
    public function moments(): array
    {
        $tz = UserTime::timezone();

        return [
            UserTime::toStorage(Carbon::parse($this->from->toDateString(), $tz)->startOfDay()),
            UserTime::toStorage(Carbon::parse($this->to->toDateString(), $tz)->endOfDay()),
        ];
    }

    /** @return array{from: string, to: string} */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }
}
