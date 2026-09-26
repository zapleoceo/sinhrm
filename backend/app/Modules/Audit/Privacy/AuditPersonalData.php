<?php

declare(strict_types=1);

namespace App\Modules\Audit\Privacy;

use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Audit\Support\AuditPolicy;
use App\Modules\Core\Contracts\PersonalDataProvider;
use App\Modules\Core\DTO\DataSubject;
use App\Modules\Core\Enums\DataSubjectType;
use App\Modules\Recruiting\Models\Application;
use Illuminate\Database\Eloquent\Builder;

/**
 * The audit log's share of a person's data: rows about the candidate (and their applications) or the employee.
 * The log stores no names or contacts (allow-list masking, no display labels), so the export is the masked rows
 * and erasure only re-masks any value outside the current allow-list (e.g. a field allowed in the past).
 */
final readonly class AuditPersonalData implements PersonalDataProvider
{
    public function __construct(private AuditPolicy $policy) {}

    public function section(): string
    {
        return 'audit_log';
    }

    public function blocker(DataSubject $subject, bool $erase): ?string
    {
        return null;
    }

    public function export(DataSubject $subject): array
    {
        return $this->rows($subject)->orderBy('id')->get()->map(fn (AuditEntry $e): array => [
            'action' => $e->action,
            'entity_type' => $e->entity_type,
            'entity_id' => $e->entity_id,
            'changes' => $e->changes,
            'at' => $e->created_at->toIso8601String(),
        ])->values()->all();
    }

    public function erase(DataSubject $subject): array
    {
        $rewritten = 0;
        foreach ($this->rows($subject)->whereNotNull('changes')->lazyById(500) as $entry) {
            $clean = $this->remask($entry->entity_type, $entry->changes ?? []);
            if ($clean !== $entry->changes) {
                $entry->forceFill(['changes' => $clean])->save();
                $rewritten++;
            }
        }

        return ['audit_rows_remasked' => $rewritten];
    }

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function remask(string $type, array $changes): array
    {
        foreach ($changes as $field => $pair) {
            if (! $this->policy->isSafe($type, $field)) {
                $changes[$field] = [
                    'from' => $pair['from'] === null ? null : AuditPolicy::MASK,
                    'to' => $pair['to'] === null ? null : AuditPolicy::MASK,
                ];
            }
        }

        return $changes;
    }

    /** @return Builder<AuditEntry> */
    private function rows(DataSubject $subject): Builder
    {
        $q = AuditEntry::query();
        if ($subject->type === DataSubjectType::Employee) {
            return $q->where('entity_type', 'employee')->where('entity_id', $subject->id);
        }
        $applicationIds = Application::query()->where('candidate_id', $subject->id)->pluck('id')->all();

        return $q->where(fn (Builder $w) => $w
            ->where(fn (Builder $c) => $c->where('entity_type', 'candidate')->where('entity_id', $subject->id))
            ->orWhere(fn (Builder $a) => $a->where('entity_type', 'application')->whereIn('entity_id', $applicationIds)));
    }
}
