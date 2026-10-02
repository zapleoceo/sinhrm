<?php

declare(strict_types=1);

namespace App\Modules\Audit\Enums;

/**
 * Sortable columns of the audit log (GET /api/audit?sort=…). A closed list mapped to ORDER BY in the repository.
 * The «changes» column is a JSON diff — not sortable.
 */
enum AuditSort: string
{
    case Time = 'time';
    case User = 'user';
    case Action = 'action';
    case Entity = 'entity';
}
