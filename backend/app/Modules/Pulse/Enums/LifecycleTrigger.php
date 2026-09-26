<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Enums;

/** Automatic lifecycle surveys: 30 / 90 days after hire, exit survey on termination. */
enum LifecycleTrigger: string
{
    case Hire30 = 'hire_30';
    case Hire90 = 'hire_90';
    case Exit = 'exit';

    /** Days after hire for hire_* triggers; null for exit. */
    public function daysAfterHire(): ?int
    {
        return match ($this) {
            self::Hire30 => 30,
            self::Hire90 => 90,
            self::Exit => null,
        };
    }
}
