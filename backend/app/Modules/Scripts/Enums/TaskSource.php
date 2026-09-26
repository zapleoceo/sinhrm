<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Enums;

/** Where a task comes from — the filter of the unified "Мої задачі" page (?source=). */
enum TaskSource: string
{
    case Recruiting = 'recruiting';
    case Workflows = 'workflows';
    case Documents = 'documents';
    case Pulse = 'pulse';
    case Desk = 'desk';

    /** @return list<string> task type values of this source */
    public function typeValues(): array
    {
        return array_values(array_map(
            static fn (TaskType $t): string => $t->value,
            array_filter(TaskType::cases(), fn (TaskType $t): bool => $t->source() === $this),
        ));
    }
}
