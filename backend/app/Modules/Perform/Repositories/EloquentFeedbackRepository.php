<?php

declare(strict_types=1);

namespace App\Modules\Perform\Repositories;

use App\Modules\Perform\Contracts\FeedbackRepository;
use App\Modules\Perform\Enums\FeedbackType;
use App\Modules\Perform\Enums\FeedbackVisibility;
use App\Modules\Perform\Models\Feedback;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentFeedbackRepository implements FeedbackRepository
{
    public function receivedBy(int $employeeId, int $limit): Collection
    {
        return $this->query()->where('to_employee_id', $employeeId)
            ->where('type', '!=', FeedbackType::Request->value)->limit($limit)->get();
    }

    public function givenBy(int $employeeId, int $limit): Collection
    {
        return $this->query()->where('from_employee_id', $employeeId)->limit($limit)->get();
    }

    public function openRequestsTo(int $employeeId, int $limit): Collection
    {
        return $this->query()->where('to_employee_id', $employeeId)
            ->where('type', FeedbackType::Request->value)->whereNull('answered_at')->limit($limit)->get();
    }

    public function about(?array $employeeIds, ?array $visibilities, int $limit): Collection
    {
        return $this->query()->where('type', '!=', FeedbackType::Request->value)
            ->when($employeeIds !== null, fn (Builder $q) => $q->whereIn('to_employee_id', $employeeIds ?? []))
            ->when($visibilities !== null, fn (Builder $q) => $q->whereIn(
                'visibility',
                array_map(static fn (FeedbackVisibility $v): string => $v->value, $visibilities ?? []),
            ))
            ->limit($limit)->get();
    }

    public function find(int $id): ?Feedback
    {
        return Feedback::query()->with(['from:id,full_name', 'to:id,full_name'])->find($id);
    }

    public function create(array $attributes): Feedback
    {
        $feedback = Feedback::query()->create($attributes);

        return $feedback->load(['from:id,full_name', 'to:id,full_name']);
    }

    public function markAnswered(Feedback $request): bool
    {
        return Feedback::query()->whereKey($request->id)->whereNull('answered_at')
            ->update(['answered_at' => Carbon::now()]) === 1;
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback());
    }

    /** @return Builder<Feedback> */
    private function query(): Builder
    {
        return Feedback::query()->with(['from:id,full_name', 'to:id,full_name'])->orderByDesc('id');
    }
}
