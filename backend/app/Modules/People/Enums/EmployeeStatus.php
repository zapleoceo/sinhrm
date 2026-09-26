<?php

declare(strict_types=1);

namespace App\Modules\People\Enums;

enum EmployeeStatus: string
{
    case Active = 'active';
    case OnLeave = 'on_leave';
    case Terminated = 'terminated';
}
