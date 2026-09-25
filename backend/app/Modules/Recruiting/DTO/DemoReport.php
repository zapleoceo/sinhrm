<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\DTO;

/** Result of RecruitingDemoData::generate(): skipped = demo data was already there. */
final readonly class DemoReport
{
    /** @param  array<string, int>  $counts */
    public function __construct(
        public bool $skipped,
        public array $counts,
        public float $seconds,
    ) {}

    public function summary(): string
    {
        if ($this->skipped) {
            return 'demo data already present, nothing to do.';
        }
        $parts = array_map(static fn (string $k, int $v): string => "$k=$v", array_keys($this->counts), $this->counts);

        return implode(', ', $parts).sprintf(' (%.2fs)', $this->seconds);
    }
}
