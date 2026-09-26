<?php

declare(strict_types=1);

namespace App\Modules\Audit\Repositories;

use App\Models\User;
use App\Modules\Audit\Contracts\AuditLogRepository;
use App\Modules\Audit\DTO\AuditFilter;
use App\Modules\Audit\DTO\AuditRecord;
use App\Modules\Audit\Models\AuditEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class EloquentAuditLogRepository implements AuditLogRepository
{
    public function store(AuditRecord $record, ?int $actorId): void
    {
        AuditEntry::query()->create([
            'user_id' => $actorId,
            'entity_type' => $record->entityType,
            'entity_id' => $record->entityId,
            'action' => $record->action->value,
            'changes' => $record->changes,
            'meta' => $record->meta,
            'created_at' => Carbon::now(),
        ]);
    }

    public function search(AuditFilter $filter): LengthAwarePaginator
    {
        $q = $this->base();
        if ($filter->userId !== null) {
            $q->where('user_id', $filter->userId);
        }
        if ($filter->entityType !== null) {
            $q->where('entity_type', $filter->entityType);
        }
        if ($filter->action !== null) {
            $q->where('action', $filter->action);
        }
        if ($filter->from !== null) {
            $q->where('created_at', '>=', $filter->from);
        }
        if ($filter->to !== null) {
            $q->where('created_at', '<', $filter->to);
        }

        return $q->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    public function history(array $entities, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->base()
            ->where(function (Builder $q) use ($entities): void {
                $q->whereRaw('1 = 0');
                foreach ($entities as $type => $ids) {
                    if ($ids !== []) {
                        $q->orWhere(fn (Builder $w) => $w->where('entity_type', $type)->whereIn('entity_id', $ids));
                    }
                }
            })
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function actors(): array
    {
        return User::query()
            ->whereIn('id', AuditEntry::query()->select('user_id')->whereNotNull('user_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u): array => ['id' => (int) $u->id, 'name' => (string) $u->name])
            ->values()
            ->all();
    }

    public function entityTypes(): array
    {
        return AuditEntry::query()->distinct()->orderBy('entity_type')->pluck('entity_type')
            ->map(fn (mixed $t): string => (string) $t)->values()->all();
    }

    public function purgeOlderThan(Carbon $before, int $limit): int
    {
        $ids = AuditEntry::query()->where('created_at', '<', $before)->orderBy('id')->limit($limit)->pluck('id')->all();

        return $ids === [] ? 0 : (int) AuditEntry::query()->whereIn('id', $ids)->delete();
    }

    /** @return Builder<AuditEntry> */
    private function base(): Builder
    {
        return AuditEntry::query()->with('user:id,name')->orderByDesc('id');
    }
}
