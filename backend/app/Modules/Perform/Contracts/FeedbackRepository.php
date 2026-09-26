<?php

declare(strict_types=1);

namespace App\Modules\Perform\Contracts;

use App\Modules\Perform\Enums\FeedbackVisibility;
use App\Modules\Perform\Models\Feedback;
use Illuminate\Database\Eloquent\Collection;

interface FeedbackRepository
{
    /** @return Collection<int, Feedback> feedback (not requests) to this employee */
    public function receivedBy(int $employeeId, int $limit): Collection;

    /** @return Collection<int, Feedback> everything this employee sent (feedback and requests) */
    public function givenBy(int $employeeId, int $limit): Collection;

    /** @return Collection<int, Feedback> open requests addressed to this employee */
    public function openRequestsTo(int $employeeId, int $limit): Collection;

    /**
     * Feedback (not requests) to these employees with one of the visibilities.
     *
     * @param  list<int>|null  $employeeIds  null = everyone
     * @param  list<FeedbackVisibility>|null  $visibilities  null = any
     * @return Collection<int, Feedback>
     */
    public function about(?array $employeeIds, ?array $visibilities, int $limit): Collection;

    public function find(int $id): ?Feedback;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): Feedback;

    /** Atomic: marks an open request answered; false when it was answered already. */
    public function markAnswered(Feedback $request): bool;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
