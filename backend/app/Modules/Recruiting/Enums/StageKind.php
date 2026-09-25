<?php

declare(strict_types=1);

namespace App\Modules\Recruiting\Enums;

/** Group of a pipeline stage: attracting, selecting, hiring or closed (rejected). Drives funnel reports. */
enum StageKind: string
{
    case Attract = 'attract';
    case Select = 'select';
    case Hire = 'hire';
    case Closed = 'closed';
}
