<?php

declare(strict_types=1);

namespace App\Modules\Workflows\DTO;

use App\Modules\Workflows\Enums\AssigneeRule;
use App\Modules\Workflows\Enums\StepAction;

/** A template step frozen into a run at start (workflow_run_steps.snapshot). */
final readonly class StepSnapshot
{
    /** @param  array<string, mixed>  $config */
    public function __construct(
        public string $title,
        public StepAction $action,
        public int $offsetDays,
        public AssigneeRule $assigneeRule,
        public ?int $assigneeUserId,
        public array $config,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $config = $data['config'] ?? [];
        $userId = $data['assignee_user_id'] ?? null;

        return new self(
            title: is_string($data['title'] ?? null) ? $data['title'] : '',
            action: StepAction::from((string) ($data['action'] ?? '')),
            offsetDays: (int) ($data['offset_days'] ?? 0),
            assigneeRule: AssigneeRule::from((string) ($data['assignee_rule'] ?? AssigneeRule::HrAdmin->value)),
            assigneeUserId: is_numeric($userId) ? (int) $userId : null,
            config: is_array($config) ? $config : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'action' => $this->action->value,
            'offset_days' => $this->offsetDays,
            'assignee_rule' => $this->assigneeRule->value,
            'assignee_user_id' => $this->assigneeUserId,
            'config' => $this->config,
        ];
    }

    public function string(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    public function int(string $key): ?int
    {
        $value = $this->config[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function bool(string $key): bool
    {
        return filter_var($this->config[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
