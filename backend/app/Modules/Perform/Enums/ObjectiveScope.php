<?php

declare(strict_types=1);

namespace App\Modules\Perform\Enums;

/** Whose objective it is: one person, a team (department), a branch or the whole company. */
enum ObjectiveScope: string
{
    case Personal = 'personal';
    case Team = 'team';
    case Branch = 'branch';
    case Company = 'company';
}
