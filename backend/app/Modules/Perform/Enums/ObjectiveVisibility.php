<?php

declare(strict_types=1);

namespace App\Modules\Perform\Enums;

/** Who may read an objective (admins and managers above the owner always can). */
enum ObjectiveVisibility: string
{
    case Public = 'public';
    case Team = 'team';
    case Private = 'private';
}
