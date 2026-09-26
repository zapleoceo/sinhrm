<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Enums;

enum ReportStatus: string
{
    case New = 'new';
    case InReview = 'in_review';
    case Closed = 'closed';
}
