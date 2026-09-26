<?php

declare(strict_types=1);

namespace App\Modules\HiringRequests\Repositories;

use App\Models\User;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\HiringRequests\Contracts\HiringRequestRepository;
use App\Modules\HiringRequests\Enums\ApprovalStatus;
use App\Modules\HiringRequests\Enums\HiringRequestStatus;
use App\Modules\HiringRequests\Enums\RouteStepKind;
use App\Modules\HiringRequests\Models\HiringApproval;
use App\Modules\HiringRequests\Models\HiringRequest;
use App\Modules\HiringRequests\Models\HiringRouteStep;
use App\Modules\HiringRequests\Models\HiringSettings;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentHiringRequestRepository implements HiringRequestRepository
{
    private const array RELATIONS = [
        'branch:id,name', 'department:id,name', 'position:id,name', 'replacedEmployee:id,full_name', 'requester:id,name',
        'recruiter:id,name', 'vacancy:id,title,status', 'approvals.approver:id,name', 'approvals.decider:id,name',
    ];

    public function list(?int $userId, array $roles, array $filter, int $limit): Collection
    {
        return HiringRequest::query()
            ->with(self::RELATIONS)
            ->when($userId !== null, fn (Builder $q) => $q->where(function (Builder $w) use ($userId, $roles): void {
                $w->where('requester_id', $userId)
                    ->orWhere('recruiter_id', $userId)
                    ->orWhereHas('approvals', fn (Builder $a) => $a->where(function (Builder $aw) use ($userId, $roles): void {
                        $aw->where('approver_id', $userId)->orWhere('decided_by', $userId);
                        if ($roles !== []) {
                            $aw->orWhere(fn (Builder $r) => $r->where('kind', RouteStepKind::Role->value)->whereIn('role', $roles)
                                ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value, ApprovalStatus::Rejected->value]));
                        }
                    }));
            }))
            ->when($filter['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when(($filter['mine'] ?? false) && $userId !== null, fn (Builder $q) => $q->where('requester_id', $userId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function find(int $id): ?HiringRequest
    {
        return HiringRequest::query()->with(self::RELATIONS)->find($id);
    }

    public function lock(int $id): ?HiringRequest
    {
        return HiringRequest::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function pending(int $limit): Collection
    {
        return HiringRequest::query()->with(self::RELATIONS)
            ->where('status', HiringRequestStatus::Pending->value)
            ->orderBy('submitted_at')
            ->limit($limit)
            ->get();
    }

    public function create(array $attributes): HiringRequest
    {
        return HiringRequest::query()->create($attributes);
    }

    public function update(HiringRequest $request, array $attributes): void
    {
        $request->fill($attributes)->save();
    }

    public function transition(HiringRequest $request, HiringRequestStatus $from, array $attributes): bool
    {
        $changed = HiringRequest::query()->whereKey($request->id)->where('status', $from->value)
            ->update($attributes + ['updated_at' => Carbon::now()]) === 1;
        if ($changed) {
            $request->refresh();
        }

        return $changed;
    }

    public function createApprovals(HiringRequest $request, array $steps): void
    {
        foreach ($steps as $step) {
            HiringApproval::query()->create(['hiring_request_id' => $request->id] + $step);
        }
    }

    public function transitionApproval(HiringApproval $approval, ApprovalStatus $from, array $attributes): bool
    {
        $changed = HiringApproval::query()->whereKey($approval->id)->where('status', $from->value)
            ->update($attributes + ['updated_at' => Carbon::now()]) === 1;
        if ($changed) {
            $approval->refresh();
        }

        return $changed;
    }

    public function skipOpenApprovals(HiringRequest $request): void
    {
        HiringApproval::query()->where('hiring_request_id', $request->id)
            ->whereIn('status', [ApprovalStatus::Waiting->value, ApprovalStatus::Pending->value])
            ->update(['status' => ApprovalStatus::Skipped->value, 'updated_at' => Carbon::now()]);
    }

    public function markNotified(HiringApproval $approval): bool
    {
        return HiringApproval::query()->whereKey($approval->id)->where('notified', false)->update(['notified' => true]) === 1;
    }

    public function markEscalated(HiringApproval $approval): bool
    {
        return HiringApproval::query()->whereKey($approval->id)->where('escalated', false)->update(['escalated' => true]) === 1;
    }

    public function pendingApprovals(): Collection
    {
        return HiringApproval::query()->with('request')
            ->where('status', ApprovalStatus::Pending->value)
            ->whereHas('request', fn (Builder $q) => $q->where('status', HiringRequestStatus::Pending->value))
            ->orderBy('id')
            ->get();
    }

    public function inProgress(): Collection
    {
        return HiringRequest::query()->with('vacancy:id,status')
            ->where('status', HiringRequestStatus::InProgress->value)
            ->whereNotNull('vacancy_id')
            ->get();
    }

    public function hiresByVacancy(array $vacancyIds): array
    {
        if ($vacancyIds === []) {
            return [];
        }
        $rows = DB::table('applications')->whereIn('vacancy_id', $vacancyIds)->where('status', ApplicationStatus::Hired->value)
            ->groupBy('vacancy_id')->selectRaw('vacancy_id, count(*) as cnt')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->vacancy_id] = (int) $r->cnt;
        }

        return $out;
    }

    public function vacancyLinked(int $vacancyId, ?int $exceptRequestId): bool
    {
        return HiringRequest::query()->where('vacancy_id', $vacancyId)
            ->when($exceptRequestId, fn (Builder $q, int $id) => $q->whereKeyNot($id))->exists();
    }

    public function usersWithRole(string $role, int $limit): array
    {
        return array_values(User::role($role)->where('status', UserStatus::Active->value)->orderBy('id')->limit($limit)->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)->all());
    }

    public function isActiveUser(int $userId): bool
    {
        return User::query()->whereKey($userId)->where('status', UserStatus::Active->value)->exists();
    }

    public function activeUsers(int $limit): array
    {
        return array_values(User::query()->where('status', UserStatus::Active->value)->orderBy('name')->limit($limit)->get(['id', 'name'])
            ->map(static fn (User $u): array => ['id' => $u->id, 'name' => (string) $u->name])->all());
    }

    public function settings(): HiringSettings
    {
        return HiringSettings::query()->orderBy('id')->first()
            ?? HiringSettings::query()->create(['form_fields' => [], 'creator_user_ids' => [], 'auto_vacancy' => true]);
    }

    public function saveSettings(array $attributes): HiringSettings
    {
        $settings = $this->settings();
        $settings->fill($attributes)->save();

        return $settings;
    }

    public function routeSteps(): Collection
    {
        return HiringRouteStep::query()->with('user:id,name')->orderBy('position')->get();
    }

    public function replaceRoute(array $steps): void
    {
        HiringRouteStep::query()->delete();
        foreach ($steps as $i => $step) {
            HiringRouteStep::query()->create(['position' => $i + 1] + $step);
        }
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback());
    }
}
