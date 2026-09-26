<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Enums;

enum AccrualMode: string
{
    case YearlyUpfront = 'yearly_upfront';
    case Monthly = 'monthly';
}
