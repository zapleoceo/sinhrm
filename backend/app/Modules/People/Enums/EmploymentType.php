<?php

declare(strict_types=1);

namespace App\Modules\People\Enums;

enum EmploymentType: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Contractor = 'contractor';
}
