<?php

declare(strict_types=1);

namespace App\Modules\Perform\Http\Resources;

use App\Modules\Perform\Models\OneOnOne;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A 1:1. notes_private_manager is present only for the meeting's manager (key absent for everyone else).
 *
 * @mixin OneOnOne
 */
final class OneOnOneResource extends JsonResource
{
    private bool $isManager = false;

    private bool $canManage = false;

    public static function for(OneOnOne $meeting, bool $isManager, bool $canManage): self
    {
        $resource = new self($meeting);
        $resource->isManager = $isManager;
        $resource->canManage = $canManage;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'manager' => ['id' => $this->manager->id, 'full_name' => $this->manager->full_name],
            'employee' => ['id' => $this->employee->id, 'full_name' => $this->employee->full_name],
            'scheduled_at' => $this->scheduled_at->toIso8601String(),
            'template_id' => $this->template_id,
            'status' => $this->status->value,
            'agenda' => $this->agenda,
            'notes_shared' => $this->notes_shared,
            'action_items' => $this->action_items,
            ...($this->isManager ? ['notes_private_manager' => $this->notes_private_manager] : []),
            'can_manage' => $this->canManage,
            'can_private_notes' => $this->isManager,
        ];
    }
}
