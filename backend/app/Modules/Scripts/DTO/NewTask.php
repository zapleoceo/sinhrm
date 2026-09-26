<?php

declare(strict_types=1);

namespace App\Modules\Scripts\DTO;

use App\Modules\Scripts\Enums\TaskType;
use Illuminate\Support\Carbon;

/**
 * A task another module asks to create once (Workflows, Documents). $ruleKey is the idempotency key together with
 * the employee: the same (employee, rule key) never produces a second task.
 */
final readonly class NewTask
{
    public function __construct(
        public int $assigneeId,
        public TaskType $type,
        public string $title,
        public Carbon $dueAt,
        public string $ruleKey,
        public int $employeeId,
        public ?string $link = null,
    ) {}

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'assignee_id' => $this->assigneeId,
            'employee_id' => $this->employeeId,
            'type' => $this->type->value,
            'title' => mb_substr($this->title, 0, 255),
            'link' => $this->link,
            'due_at' => $this->dueAt,
            'rule_key' => $this->ruleKey,
        ];
    }
}
