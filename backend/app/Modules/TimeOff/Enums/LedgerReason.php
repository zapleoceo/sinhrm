<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Enums;

enum LedgerReason: string
{
    case Accrual = 'accrual';
    case Request = 'request';
    case Adjustment = 'adjustment';
    case CarryOver = 'carry_over';
    case Expiry = 'expiry';
}
