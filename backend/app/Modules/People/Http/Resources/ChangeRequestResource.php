<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Resources;

use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Enums\ChangeableField;
use App\Modules\People\Models\EmployeeChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeChangeRequest */
final class ChangeRequestResource extends JsonResource
{
    /**
     * Proposed values that belong to the PII tier (EmployeeResource: admins and the employee only). A manager
     * decides the request of a subordinate without reading them: the keys come back in `hidden_changes`, the values
     * never leave the server. `phone` is directory-tier and stays visible.
     *
     * @var list<string>
     */
    private const array PII_FIELDS = [
        ChangeableField::PersonalEmail->value,
        ChangeableField::Address->value,
        ChangeableField::EmergencyContact->value,
    ];

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
        [$changes, $hidden] = $this->visibleChanges();

        return [
            'id' => $this->id,
            'employee' => ['id' => $this->employee->id, 'full_name' => $this->employee->full_name],
            'changes' => $changes,
            'hidden_changes' => $hidden,
            'status' => $this->status->value,
            'comment' => $this->comment,
            'requested_by' => $this->requester === null ? null : ['id' => $this->requester->id, 'name' => $this->requester->name],
            'decided_by' => $this->decider === null ? null : ['id' => $this->decider->id, 'name' => $this->decider->name],
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_comment' => $this->decision_comment,
            'can_decide' => $this->context !== null && $this->context->canDecideOrBreakGlass($this->employee_id),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Without the PII tier (a manager, or no context at all) the personal values are dropped and only their field
     * names are reported.
     *
     * @return array{0: array<string, string|null>, 1: list<string>}
     */
    private function visibleChanges(): array
    {
        /** @var array<string, string|null> $changes */
        $changes = $this->changes;
        if ($this->context !== null && $this->context->canSeePii($this->employee_id)) {
            return [$changes, []];
        }
        $hidden = [];
        foreach (self::PII_FIELDS as $field) {
            if (array_key_exists($field, $changes)) {
                unset($changes[$field]);
                $hidden[] = $field;
            }
        }

        return [$changes, $hidden];
    }
}
