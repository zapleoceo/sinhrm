<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Events;

use App\Modules\Recruiting\Models\Touchpoint;

/** A touchpoint was stored or got linked to a candidate (manual log, ingestion, inbox link). */
final readonly class TouchpointRecorded
{
    public function __construct(public Touchpoint $touchpoint) {}
}
