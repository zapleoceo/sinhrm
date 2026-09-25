<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Contracts;

use App\Modules\GoogleWorkspace\Models\SheetImport;
use Illuminate\Database\Eloquent\Collection;

interface SheetImportRepository
{
    /** @return Collection<int, SheetImport> newest first */
    public function all(): Collection;

    /** @return Collection<int, SheetImport> */
    public function autoSync(): Collection;

    public function find(int $id): ?SheetImport;

    /**
     * Create or update by (spreadsheet_id, sheet).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsert(string $spreadsheetId, string $sheet, array $attributes): SheetImport;

    /** @param  array<string, mixed>  $attributes */
    public function update(SheetImport $import, array $attributes): SheetImport;
}
