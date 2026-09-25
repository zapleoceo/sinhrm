<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Enums;

/** GET /api/tasks?due=: today = open and due by the end of today (overdue included); overdue = open and due before today. */
enum TaskDue: string
{
    case Today = 'today';
    case Overdue = 'overdue';
}
