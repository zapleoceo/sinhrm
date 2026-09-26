<?php

declare(strict_types=1);

namespace App\Modules\Time\DTO;

/** A working week: ISO weekdays (1 = Monday) and hours per working day. */
final readonly class Schedule
{
    /** Fallback when neither the employee, the branch nor the company has a schedule: 8 h, Monday–Friday. */
    public const array DEFAULT_DAYS = [1, 2, 3, 4, 5];

    public const float DEFAULT_HOURS = 8.0;

    /** @param  list<int>  $days */
    public function __construct(public array $days, public float $hoursPerDay, public string $source) {}

    public static function fallback(): self
    {
        return new self(self::DEFAULT_DAYS, self::DEFAULT_HOURS, 'default');
    }

    /**
     * From a People work_schedule / schedule row ({days, hours_per_day}); null when incomplete.
     *
     * @param  array<string, mixed>|null  $raw
     */
    public static function fromArray(?array $raw, string $source): ?self
    {
        if ($raw === null || ! isset($raw['days'], $raw['hours_per_day']) || ! is_array($raw['days']) || ! is_numeric($raw['hours_per_day'])) {
            return null;
        }
        $days = array_values(array_unique(array_filter(array_map('intval', $raw['days']), static fn (int $d): bool => $d >= 1 && $d <= 7)));
        sort($days);

        return new self($days, (float) $raw['hours_per_day'], $source);
    }

    /** @return array{days: list<int>, hours_per_day: float, source: string} */
    public function toArray(): array
    {
        return ['days' => $this->days, 'hours_per_day' => $this->hoursPerDay, 'source' => $this->source];
    }
}
