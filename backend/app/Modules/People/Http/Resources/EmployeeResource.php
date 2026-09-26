<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Resources;

use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Employee in three tiers: directory (always), job (admin, self, managers above), PII (admin, self).
 * Hidden tiers are omitted from the payload entirely (not nulled), and `access` tells the UI which tabs to show.
 *
 * @mixin Employee
 */
final class EmployeeResource extends JsonResource
{
    private ?PeopleContext $context = null;

    public static function for(Employee $employee, ?PeopleContext $context): self
    {
        $resource = new self($employee);
        $resource->context = $context;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $ref = static fn (?object $m, string $label = 'name'): ?array => $m === null ? null : ['id' => $m->id, 'name' => $m->{$label}];
        $data = [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'avatar_url' => $this->avatar_url,
            'work_email' => $this->work_email,
            'phone' => $this->phone,
            'status' => $this->status->value,
            'branch' => $this->relationLoaded('branch') ? $ref($this->branch) : null,
            'department' => $this->relationLoaded('department') ? $ref($this->department) : null,
            'position' => $this->relationLoaded('position') ? $ref($this->position) : null,
            'manager' => $this->relationLoaded('manager') ? $ref($this->manager, 'full_name') : null,
        ];
        if ($this->context === null) {
            return $data;
        }
        $flags = $this->context->flags($this->id);
        $data['access'] = $flags;
        if ($flags['job']) {
            $data += [
                'user_id' => $this->user_id,
                'branch_id' => $this->branch_id,
                'department_id' => $this->department_id,
                'position_id' => $this->position_id,
                'manager_id' => $this->manager_id,
                'hired_at' => $this->hired_at->toDateString(),
                'fired_at' => $this->fired_at?->toDateString(),
                'termination_reason' => $flags['manage'] ? $this->termination_reason : null,
                'employment_type' => $this->employment_type->value,
                'work_schedule' => $this->work_schedule,
                'reports_count' => (int) ($this->reports_count ?? 0),
                'candidate_id' => $this->candidate_id,
            ];
        }
        if ($flags['pii']) {
            $data += [
                'birth_date' => $this->birth_date?->toDateString(),
                'personal_email' => $this->personal_email,
                'address' => $this->address,
                'emergency_contact' => $this->emergency_contact,
                'custom_fields' => $this->custom_fields ?? (object) [],
            ];
        }

        return $data;
    }
}
