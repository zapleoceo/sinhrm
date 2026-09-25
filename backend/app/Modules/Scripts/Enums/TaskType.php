<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Enums;

enum TaskType: string
{
    case Followup = 'followup';
    case Manual = 'manual';
    /** "Call the new applicant within an hour" — created by the mail agent for a job-board application. */
    case NewApplicant = 'new_applicant';
}
