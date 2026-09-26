<?php

declare(strict_types=1);

namespace App\Modules\Perform\Repositories;

use App\Modules\Perform\Contracts\OneOnOneRepository;
use App\Modules\Perform\Models\OneOnOne;
use App\Modules\Perform\Models\OneOnOneTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class EloquentOneOnOneRepository implements OneOnOneRepository
{
    public function list(?array $visibleIds, ?int $selfId, ?int $employeeId, ?string $status, int $limit): Collection
    {
        return OneOnOne::query()->with(['manager:id,full_name', 'employee:id,full_name'])
            ->when($visibleIds !== null, fn (Builder $q) => $q->where(function (Builder $w) use ($visibleIds, $selfId): void {
                $w->whereIn('employee_id', $visibleIds ?? []);
                if ($selfId !== null) {
                    $w->orWhere('manager_employee_id', $selfId);
                }
            }))
            ->when($employeeId !== null, fn (Builder $q) => $q->where('employee_id', $employeeId))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->orderByDesc('scheduled_at')->orderByDesc('id')->limit($limit)->get();
    }

    public function find(int $id): ?OneOnOne
    {
        return OneOnOne::query()->with(['manager:id,full_name', 'employee:id,full_name'])->find($id);
    }

    public function create(array $attributes): OneOnOne
    {
        $meeting = OneOnOne::query()->create($attributes);

        return $this->find($meeting->id) ?? $meeting;
    }

    public function update(OneOnOne $meeting, array $attributes): OneOnOne
    {
        $meeting->fill($attributes)->save();

        return $meeting;
    }

    public function delete(OneOnOne $meeting): void
    {
        $meeting->delete();
    }

    public function templates(): Collection
    {
        return OneOnOneTemplate::query()->orderBy('name')->orderBy('id')->get();
    }

    public function findTemplate(int $id): ?OneOnOneTemplate
    {
        return OneOnOneTemplate::query()->find($id);
    }

    public function saveTemplate(?OneOnOneTemplate $template, array $attributes): OneOnOneTemplate
    {
        $template ??= new OneOnOneTemplate;
        $template->fill($attributes)->save();

        return $template;
    }

    public function deleteTemplate(OneOnOneTemplate $template): void
    {
        $template->delete();
    }
}
