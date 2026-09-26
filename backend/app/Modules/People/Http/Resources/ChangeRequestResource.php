<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Resources;

use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Models\EmployeeChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeChangeRequest */
final class ChangeRequestResource extends JsonResource
{
    private ?PeopleContext $context = null;

    public static function for(EmployeeChangeRequest $request, PeopleContext $context): self
    {
        $resource = new self($request);
        $resource->context = $context;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => ['id' => $this->employee->id, 'full_name' => $this->employee->full_name],
            'changes' => $this->changes,
            'status' => $this->status->value,
            'comment' => $this->comment,
            'requested_by' => $this->requester === null ? null : ['id' => $this->requester->id, 'name' => $this->requester->name],
            'decided_by' => $this->decider === null ? null : ['id' => $this->decider->id, 'name' => $this->decider->name],
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_comment' => $this->decision_comment,
            'can_decide' => $this->context !== null && $this->context->canDecideFor($this->employee_id),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
