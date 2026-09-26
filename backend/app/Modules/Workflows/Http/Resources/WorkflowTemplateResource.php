<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Http\Resources;

use App\Modules\Integrations\DTO\SecretMeta;
use App\Modules\Workflows\Models\WorkflowStep;
use App\Modules\Workflows\Models\WorkflowTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A template with its steps. webhook_secret: only whether the signing key is set and its mask — never the value.
 *
 * @mixin WorkflowTemplate
 */
final class WorkflowTemplateResource extends JsonResource
{
    private ?SecretMeta $secret = null;

    public static function withSecret(WorkflowTemplate $template, ?SecretMeta $secret): self
    {
        $resource = new self($template);
        $resource->secret = $secret;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'trigger' => $this->trigger->value,
            'active' => $this->active,
            'probation_days' => $this->probation_days,
            'runs_count' => (int) ($this->runs_count ?? 0),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'webhook_secret' => [
                'is_set' => $this->secret !== null && $this->secret->isSet,
                'masked' => $this->secret?->masked,
                'updated_at' => $this->secret?->updatedAt?->toIso8601String(),
            ],
            'steps' => $this->steps->map(static fn (WorkflowStep $s): array => [
                'id' => $s->id,
                'position' => $s->position,
                'title' => $s->title,
                'action' => $s->action->value,
                'offset_days' => $s->offset_days,
                'assignee_rule' => $s->assignee_rule->value,
                'assignee_user_id' => $s->assignee_user_id,
                'config' => (object) $s->config,
            ])->values()->all(),
        ];
    }
}
