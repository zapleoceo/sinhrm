<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Contracts;

use App\Modules\Core\DTO\DataSubject;
use App\Modules\Privacy\Models\PrivacyRequest;
use Illuminate\Database\Eloquent\Collection;

/** Privacy's own tables: the request journal (privacy_requests) and the single settings row (privacy_settings). */
interface PrivacyRepository
{
    /** @param  array<string, int>|null  $counts  per-section totals (erase), null for export */
    public function journal(DataSubject $subject, string $action, string $trigger, ?string $reason, ?int $actorId, ?array $counts): void;

    /**
     * Journal of one person, newest first.
     *
     * @return Collection<int, PrivacyRequest>
     */
    public function requestsFor(DataSubject $subject, int $limit): Collection;

    /** Months after which rejected candidates are anonymized; null — the rule is off. */
    public function retentionRejectedMonths(): ?int;

    /** Stores the rule and returns the stored value. */
    public function setRetentionRejectedMonths(?int $months): ?int;
}
