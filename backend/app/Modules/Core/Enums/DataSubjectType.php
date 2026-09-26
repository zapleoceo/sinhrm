<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

/** Whose personal data a request is about (Law of Ukraine No. 2297-VI "On personal data protection"). */
enum DataSubjectType: string
{
    case Candidate = 'candidate';
    case Employee = 'employee';
}
