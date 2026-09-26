<?php

declare(strict_types=1);

namespace App\Modules\Desk\Contracts;

use App\Modules\Desk\Models\DeskAttachment;
use App\Modules\Desk\Models\DeskCase;
use App\Modules\Desk\Models\DeskCategory;
use App\Modules\Desk\Models\DeskComment;
use Illuminate\Database\Eloquent\Collection;

interface DeskRepository
{
    /** @return Collection<int, DeskCategory> */
    public function categories(bool $withInactive): Collection;

    public function findCategory(int $id): ?DeskCategory;

    /** @param  array<string, mixed>  $attributes */
    public function saveCategory(?DeskCategory $category, array $attributes): DeskCategory;

    /**
     * @param  array{employee_id?: int|null, status?: string|null, assignee_id?: int|null, category_id?: int|null, open?: bool}  $filter
     * @return Collection<int, DeskCase>
     */
    public function cases(array $filter, int $limit): Collection;

    /**
     * How many cases() would find without the limit (sidebar counter).
     *
     * @param  array{employee_id?: int|null, status?: string|null, assignee_id?: int|null, category_id?: int|null, open?: bool}  $filter
     */
    public function countCases(array $filter): int;

    public function findCase(int $id): ?DeskCase;

    /** @param  array<string, mixed>  $attributes */
    public function createCase(array $attributes): DeskCase;

    /** @param  array<string, mixed>  $attributes */
    public function updateCase(DeskCase $case, array $attributes): DeskCase;

    /** @param  array<string, mixed>  $attributes */
    public function addComment(array $attributes): DeskComment;

    /** @param  array<string, mixed>  $attributes */
    public function addAttachment(array $attributes): DeskAttachment;

    public function attachmentCount(int $caseId): int;

    public function findAttachment(int $caseId, int $id): ?DeskAttachment;

    /**
     * Open cases whose category has an SLA target (for the desk.sla job), with the category.
     *
     * @return Collection<int, DeskCase>
     */
    public function openWithSla(): Collection;

    /** True for an active HR staff user (superadmin/admin/hr_manager) (who may be an assignee). */
    public function isHrUser(int $userId): bool;

    /** The first active HR staff user — the fallback assignee of breach tasks. */
    public function fallbackHrUserId(): ?int;
}
