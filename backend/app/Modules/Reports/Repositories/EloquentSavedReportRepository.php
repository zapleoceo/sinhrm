<?php

declare(strict_types=1);

namespace App\Modules\Reports\Repositories;

use App\Modules\Reports\Contracts\SavedReportRepository;
use App\Modules\Reports\Models\SavedReport;
use Illuminate\Database\Eloquent\Collection;

final class EloquentSavedReportRepository implements SavedReportRepository
{
    public function ofUser(int $userId): Collection
    {
        return SavedReport::query()->where('user_id', $userId)->orderBy('name')->orderBy('id')->get();
    }

    public function findOwn(int $userId, int $id): ?SavedReport
    {
        return SavedReport::query()->where('user_id', $userId)->find($id);
    }

    public function save(?SavedReport $report, array $attributes): SavedReport
    {
        $report ??= new SavedReport;
        $report->fill($attributes)->save();

        return $report;
    }

    public function delete(SavedReport $report): void
    {
        $report->delete();
    }
}
