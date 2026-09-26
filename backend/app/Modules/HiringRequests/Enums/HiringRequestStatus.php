<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Enums;

/**
 * Life cycle (tz2 "Черновик → На рассмотрении → Согласована / Отклонена → Есть вакансия"):
 * draft → pending (on the route) → approved → in_progress (a vacancy is linked) → closed;
 * pending → rejected; draft | pending | approved → cancelled.
 */
enum HiringRequestStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case InProgress = 'in_progress';
    case Closed = 'closed';

    public function isFinal(): bool
    {
        return in_array($this, [self::Rejected, self::Cancelled, self::Closed], true);
    }

    public function isCancellable(): bool
    {
        return in_array($this, [self::Draft, self::Pending, self::Approved], true);
    }
}
