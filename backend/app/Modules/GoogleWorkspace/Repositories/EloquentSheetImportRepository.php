<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Repositories;

use App\Modules\GoogleWorkspace\Contracts\SheetImportRepository;
use App\Modules\GoogleWorkspace\Models\SheetImport;
use Illuminate\Database\Eloquent\Collection;

final class EloquentSheetImportRepository implements SheetImportRepository
{
    public function all(): Collection
    {
        return SheetImport::query()->orderByDesc('id')->get();
    }

    public function autoSync(): Collection
    {
        return SheetImport::query()->where('auto_sync', true)->orderBy('id')->get();
    }

    public function find(int $id): ?SheetImport
    {
        return SheetImport::query()->find($id);
    }

    public function upsert(string $spreadsheetId, string $sheet, array $attributes): SheetImport
    {
        return SheetImport::query()->updateOrCreate(['spreadsheet_id' => $spreadsheetId, 'sheet' => $sheet], $attributes);
    }

    public function update(SheetImport $import, array $attributes): SheetImport
    {
        $import->fill($attributes)->save();

        return $import;
    }
}
