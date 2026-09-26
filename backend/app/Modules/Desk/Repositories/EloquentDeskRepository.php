<?php

declare(strict_types=1);

namespace App\Modules\Desk\Repositories;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Desk\Contracts\DeskRepository;
use App\Modules\Desk\Enums\CaseStatus;
use App\Modules\Desk\Models\DeskAttachment;
use App\Modules\Desk\Models\DeskCase;
use App\Modules\Desk\Models\DeskCategory;
use App\Modules\Desk\Models\DeskComment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class EloquentDeskRepository implements DeskRepository
{
    public function categories(bool $withInactive): Collection
    {
        return DeskCategory::query()
            ->when(! $withInactive, static fn (Builder $q) => $q->where('active', true))
            ->orderBy('name')
            ->get();
    }

    public function findCategory(int $id): ?DeskCategory
    {
        return DeskCategory::query()->find($id);
    }

    public function saveCategory(?DeskCategory $category, array $attributes): DeskCategory
    {
        $category ??= new DeskCategory;
        $category->fill($attributes)->save();

        return $category;
    }

    public function cases(array $filter, int $limit): Collection
    {
        return DeskCase::query()
            ->with(['employee:id,full_name', 'category', 'assignee:id,name'])
            ->when(isset($filter['employee_id']), static fn (Builder $q) => $q->where('employee_id', $filter['employee_id']))
            ->when(isset($filter['status']), static fn (Builder $q) => $q->where('status', $filter['status']))
            ->when(isset($filter['assignee_id']), static fn (Builder $q) => $q->where('assignee_id', $filter['assignee_id']))
            ->when(isset($filter['category_id']), static fn (Builder $q) => $q->where('category_id', $filter['category_id']))
            ->when(($filter['open'] ?? false) === true, static fn (Builder $q) => $q->whereIn('status', CaseStatus::openValues()))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function findCase(int $id): ?DeskCase
    {
        return DeskCase::query()->with(['employee:id,full_name,user_id', 'category', 'assignee:id,name'])->find($id);
    }

    public function createCase(array $attributes): DeskCase
    {
        return DeskCase::query()->create($attributes);
    }

    public function updateCase(DeskCase $case, array $attributes): DeskCase
    {
        $case->fill($attributes)->save();

        return $case;
    }

    public function addComment(array $attributes): DeskComment
    {
        return DeskComment::query()->create($attributes);
    }

    public function addAttachment(array $attributes): DeskAttachment
    {
        return DeskAttachment::query()->create($attributes);
    }

    public function attachmentCount(int $caseId): int
    {
        return DeskAttachment::query()->where('case_id', $caseId)->count();
    }

    public function findAttachment(int $caseId, int $id): ?DeskAttachment
    {
        return DeskAttachment::query()->where('case_id', $caseId)->find($id);
    }

    public function openWithSla(): Collection
    {
        return DeskCase::query()
            ->with('category')
            ->whereIn('status', CaseStatus::openValues())
            ->whereHas('category', static fn (Builder $q) => $q->whereNotNull('first_response_hours')->orWhereNotNull('resolve_hours'))
            ->orderBy('id')
            ->get();
    }

    public function isHrUser(int $userId): bool
    {
        return User::query()->whereKey($userId)->where('status', UserStatus::Active->value)->role(UserRole::valuesOf(UserRole::hrStaff()))->exists();
    }

    public function fallbackHrUserId(): ?int
    {
        $id = User::query()->where('status', UserStatus::Active->value)->role(UserRole::valuesOf(UserRole::hrStaff()))->orderBy('id')->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
