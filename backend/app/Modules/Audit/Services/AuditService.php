<?php

declare(strict_types=1);

namespace App\Modules\Audit\Services;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Audit\Contracts\AuditLogRepository;
use App\Modules\Audit\DTO\AuditFilter;
use App\Modules\Audit\DTO\AuditRecord;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Audit\Support\AuditPolicy;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class AuditService implements AuditLogger
{
    public function __construct(
        private AuditLogRepository $repository,
        private AuditPolicy $policy,
        private AuthFactory $auth,
    ) {}

    public function record(string $entityType, int $entityId, AuditAction $action, ?array $changes = null, ?array $meta = null, ?int $actorId = null): void
    {
        $this->policy->assertEntityType($entityType);
        if ($changes !== null) {
            $changes = $this->policy->sanitize($changes);
            if ($changes === [] && $action === AuditAction::Updated) {
                return; // only technical fields changed
            }
        }
        $actor = $actorId ?? $this->auth->guard()->id();

        $this->repository->store(
            new AuditRecord($entityType, $entityId, $action, $changes === [] ? null : $changes, $meta),
            is_numeric($actor) ? (int) $actor : null,
        );
    }

    /** @return LengthAwarePaginator<int, AuditEntry> */
    public function search(AuditFilter $filter): LengthAwarePaginator
    {
        return $this->repository->search($filter);
    }

    /**
     * @param  array<string, list<int>>  $entities
     * @return LengthAwarePaginator<int, AuditEntry>
     */
    public function history(array $entities, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->history($entities, $page, $perPage);
    }

    /** @return array{entity_types: list<string>, actions: list<string>, users: list<array{id: int, name: string}>} */
    public function options(): array
    {
        return [
            'entity_types' => $this->repository->entityTypes(),
            'actions' => AuditAction::values(),
            'users' => $this->repository->actors(),
        ];
    }
}
