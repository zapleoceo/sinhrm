<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Models;

use App\Modules\Workflows\DTO\StepSnapshot;
use App\Modules\Workflows\Enums\AssigneeRule;
use App\Modules\Workflows\Enums\StepAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $template_id
 * @property int $position
 * @property string $title
 * @property StepAction $action
 * @property int $offset_days
 * @property AssigneeRule $assignee_rule
 * @property int|null $assignee_user_id
 * @property array<string, mixed> $config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class WorkflowStep extends Model
{
    protected $fillable = ['template_id', 'position', 'title', 'action', 'offset_days', 'assignee_rule', 'assignee_user_id', 'config'];

    public function snapshot(): StepSnapshot
    {
        return new StepSnapshot(
            title: $this->title,
            action: $this->action,
            offsetDays: $this->offset_days,
            assigneeRule: $this->assignee_rule,
            assigneeUserId: $this->assignee_user_id,
            config: $this->config,
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => StepAction::class,
            'assignee_rule' => AssigneeRule::class,
            'offset_days' => 'integer',
            'position' => 'integer',
            'config' => 'array',
        ];
    }
}
