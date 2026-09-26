<?php

declare(strict_types=1);

namespace App\Modules\Audit\Enums;

enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case StatusChanged = 'status_changed';
    case RoleChanged = 'role_changed';
    case StageChanged = 'stage_changed';
    case SecretSet = 'secret_set';
    case SecretCleared = 'secret_cleared';
    case PromptActivated = 'prompt_activated';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $a): string => $a->value, self::cases());
    }
}
