<?php

declare(strict_types=1);

namespace App\Modules\Directory\Enums;

enum UpsertOutcome
{
    case Created;
    case Updated;
    case Unchanged;
}
