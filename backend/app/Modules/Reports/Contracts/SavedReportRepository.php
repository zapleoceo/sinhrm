<?php

declare(strict_types=1);

namespace App\Modules\Reports\Contracts;

use App\Modules\Reports\Models\SavedReport;
use Illuminate\Database\Eloquent\Collection;

interface SavedReportRepository
{
    /** @return Collection<int, SavedReport> */
    public function ofUser(int $userId): Collection;

    /** Only the owner's report (others: null → 404). */
    public function findOwn(int $userId, int $id): ?SavedReport;

    /** @param  array<string, mixed>  $attributes */
    public function save(?SavedReport $report, array $attributes): SavedReport;

    public function delete(SavedReport $report): void;
}
