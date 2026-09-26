<?php

declare(strict_types=1);

namespace App\Modules\Perform\Enums;

/** Who reviews whom in a cycle: the subject themself, their manager, peers (same manager) or reports (upward, 360). */
enum ReviewType: string
{
    case Self = 'self';
    case Manager = 'manager';
    case Peer = 'peer';
    case Upward = 'upward';

    /** Peer and upward reviewers are protected: never named to the subject, results need a minimum group. */
    public function isProtected(): bool
    {
        return $this === self::Peer || $this === self::Upward;
    }
}
