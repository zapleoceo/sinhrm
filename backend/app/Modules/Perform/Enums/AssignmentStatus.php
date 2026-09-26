<?php

declare(strict_types=1);

namespace App\Modules\Perform\Enums;

/** A reviewer's form in a cycle. */
enum AssignmentStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
}
