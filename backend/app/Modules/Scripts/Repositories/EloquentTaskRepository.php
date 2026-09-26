<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Repositories;

use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Enums\ApplicationStatus;
use App\Modules\Recruiting\Enums\Channel;
use App\Modules\Recruiting\Enums\Direction;
use App\Modules\Scripts\Contracts\TaskRepository;
use App\Modules\Scripts\DTO\ApplicationActivity;
use App\Modules\Scripts\DTO\TaskFilter;
use App\Modules\Scripts\Enums\TaskDue;
use App\Modules\Scripts\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentTaskRepository implements TaskRepository
{
    /** Outbound "messages" for the no_reply / link_not_completed rules (a call is a conversation, not a message). */
    private const array MESSAGE_CHANNELS = [Channel::Telegram, Channel::Whatsapp, Channel::Viber, Channel::Email];

    private const array RELATIONS = ['candidate', 'application.vacancy', 'employee'];

    public function list(Scope $scope, TaskFilter $filter, Carbon $now, int $limit): Collection
    {
        return $this->filtered($scope, $filter, $now)
            ->with(self::RELATIONS)
            ->orderByRaw('case when done_at is null then 0 else 1 end')
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function count(Scope $scope, TaskFilter $filter, Carbon $now): int
    {
        return $this->filtered($scope, $filter, $now)->count();
    }

    /** @return Builder<Task> */
    private function filtered(Scope $scope, TaskFilter $filter, Carbon $now): Builder
    {
        return $this->scoped($scope)
            ->when($filter->source !== null, fn (Builder $q) => $q->whereIn('type', $filter->source?->typeValues() ?? []))
            ->when($filter->employeeId, fn (Builder $q, int $id) => $q->where('employee_id', $id))
            ->when($filter->mine, fn (Builder $q) => $q->where('assignee_id', $scope->userId))
            ->when($filter->candidateId, fn (Builder $q, int $id) => $q->where('candidate_id', $id))
            ->when(! $filter->withDone, fn (Builder $q) => $q->whereNull('done_at'))
            ->when($filter->due === TaskDue::Today, fn (Builder $q) => $q->where('due_at', '<=', $now->copy()->endOfDay()))
            ->when($filter->due === TaskDue::Overdue, fn (Builder $q) => $q->where('due_at', '<', $now->copy()->startOfDay()));
    }

    public function find(int $id): ?Task
    {
        return Task::query()->with(self::RELATIONS)->find($id);
    }

    public function isVisible(Scope $scope, Task $task): bool
    {
        return $this->scoped($scope)->whereKey($task->id)->exists();
    }

    public function update(Task $task, array $attributes): Task
    {
        $task->fill($attributes)->save();

        return $task;
    }

    public function markDone(Task $task, bool $done, Carbon $at): bool
    {
        $changed = Task::query()->whereKey($task->id)
            ->when($done, fn (Builder $q) => $q->whereNull('done_at'), fn (Builder $q) => $q->whereNotNull('done_at'))
            ->update(['done_at' => $done ? $at : null, 'updated_at' => Carbon::now()]) === 1;
        if ($changed) {
            $task->refresh();
        }

        return $changed;
    }

    public function createFollowupOnce(array $attributes): bool
    {
        $now = Carbon::now();

        return Task::query()->insertOrIgnore([$attributes + ['created_at' => $now, 'updated_at' => $now]]) > 0;
    }

    public function createOnce(array $attributes): Task
    {
        $this->createFollowupOnce($attributes);
        $task = Task::query()->with(self::RELATIONS)
            ->where('employee_id', $attributes['employee_id'] ?? null)
            ->where('rule_key', $attributes['rule_key'])
            ->first();
        assert($task instanceof Task);

        return $task;
    }

    public function closeByRulePrefix(string $prefix, Carbon $at): int
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix);

        return Task::query()->whereNull('done_at')->whereRaw("rule_key like ? escape '!'", [$escaped.'%'])
            ->update(['done_at' => $at, 'updated_at' => Carbon::now()]);
    }

    public function findByRule(int $employeeId, string $ruleKey): ?Task
    {
        return Task::query()->where('employee_id', $employeeId)->where('rule_key', $ruleKey)->first();
    }

    public function activities(): array
    {
        $messageChannels = array_map(static fn (Channel $c): string => $c->value, self::MESSAGE_CHANNELS);
        $rows = DB::table('applications as a')
            ->join('vacancies as v', 'v.id', '=', 'a.vacancy_id')
            ->where('a.status', ApplicationStatus::Active->value)
            ->select(['a.id', 'a.candidate_id', 'v.recruiter_id', 'a.created_at', 'a.last_touch_at'])
            ->selectSub(fn (QueryBuilder $q) => $q->from('touchpoints as t')->selectRaw('max(t.occurred_at)')
                ->whereColumn('t.candidate_id', 'a.candidate_id')
                ->where('t.direction', Direction::Out->value)
                ->whereIn('t.channel', $messageChannels), 'last_out_at')
            ->selectSub(fn (QueryBuilder $q) => $q->from('touchpoints as t')->selectRaw('max(t.occurred_at)')
                ->whereColumn('t.candidate_id', 'a.candidate_id')
                ->where('t.direction', Direction::In->value)
                ->where('t.channel', '!=', Channel::System->value), 'last_in_at')
            ->selectSub(fn (QueryBuilder $q) => $q->from('stage_changes as sc')->selectRaw('max(sc.at)')
                ->whereColumn('sc.application_id', 'a.id'), 'last_stage_at')
            ->orderBy('a.id')
            ->get();

        $date = static fn (mixed $v): ?Carbon => $v === null ? null : Carbon::parse((string) $v);

        return array_values($rows->map(static fn (object $r): ApplicationActivity => new ApplicationActivity(
            applicationId: (int) $r->id,
            candidateId: (int) $r->candidate_id,
            recruiterId: (int) $r->recruiter_id,
            createdAt: $date($r->created_at) ?? Carbon::now(),
            lastTouchAt: $date($r->last_touch_at),
            lastOutboundAt: $date($r->last_out_at),
            lastInboundAt: $date($r->last_in_at),
            lastStageChangeAt: $date($r->last_stage_at),
        ))->all());
    }

    /**
     * Restricted users see tasks assigned to them and tasks on applications of vacancies in their branches.
     *
     * @return Builder<Task>
     */
    private function scoped(Scope $scope): Builder
    {
        return Task::query()->when(! $scope->isUnrestricted(), fn (Builder $q) => $q->where(function (Builder $w) use ($scope): void {
            $w->where('assignee_id', $scope->userId)->orWhereHas(
                'application.vacancy',
                fn (Builder $v) => $v->whereIn('branch_id', $scope->branchIds ?? []),
            );
        }));
    }
}
