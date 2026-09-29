<?php

declare(strict_types=1);

namespace App\Modules\People\Enums;

/** Who the person picker searches among: directory employees, my managed subtree, or system users (HR only). */
enum PickerScope: string
{
    case Employees = 'employees';
    case Subordinates = 'subordinates';
    case Users = 'users';
}
