<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Repositories;

use App\Modules\Core\DTO\DataSubject;
use App\Modules\Privacy\Contracts\PrivacyRepository;
use App\Modules\Privacy\Models\PrivacyRequest;
use App\Modules\Privacy\Models\PrivacySettings;
use Illuminate\Database\Eloquent\Collection;

final class EloquentPrivacyRepository implements PrivacyRepository
{
    public function journal(DataSubject $subject, string $action, string $trigger, ?string $reason, ?int $actorId, ?array $counts): void
    {
        PrivacyRequest::query()->create([
            'subject_type' => $subject->type->value,
            'subject_id' => $subject->id,
            'action' => $action,
            'trigger' => $trigger,
            'reason' => $reason,
            'actor_id' => $actorId,
            'counts' => $counts,
        ]);
    }

    public function requestsFor(DataSubject $subject, int $limit): Collection
    {
        return PrivacyRequest::query()->where('subject_type', $subject->type->value)->where('subject_id', $subject->id)
            ->orderByDesc('id')->limit($limit)->get();
    }

    public function retentionRejectedMonths(): ?int
    {
        return PrivacySettings::current()->retention_rejected_months;
    }

    public function setRetentionRejectedMonths(?int $months): ?int
    {
        $settings = PrivacySettings::current();
        $settings->update(['retention_rejected_months' => $months]);

        return $settings->retention_rejected_months;
    }
}
