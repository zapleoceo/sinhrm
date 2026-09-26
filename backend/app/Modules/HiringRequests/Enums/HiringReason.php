<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Enums;

enum HiringReason: string
{
    case NewPosition = 'new_position';
    /** Needs replaced_employee_id. */
    case Replacement = 'replacement';
}
