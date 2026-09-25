<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Http\Controllers;

use App\Models\User;
use App\Modules\GoogleWorkspace\Contracts\SheetImportRepository;
use App\Modules\GoogleWorkspace\Http\Requests\InspectSheetRequest;
use App\Modules\GoogleWorkspace\Http\Requests\UpdateSheetImportRequest;
use App\Modules\GoogleWorkspace\Models\SheetImport;
use App\Modules\GoogleWorkspace\Services\SheetsImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin → "Import from Google Sheets" (superadmin). */
final readonly class SheetsImportController
{
    public function __construct(private SheetsImportService $service, private SheetImportRepository $imports) {}

    public function inspect(InspectSheetRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->service->inspect($request->url(), $request->sheet())]);
    }

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => $this->imports->all()->map(fn (SheetImport $i): array => self::present($i))->values()]);
    }

    public function store(InspectSheetRequest $request): JsonResponse
    {
        $result = $this->service->saveAndRun($this->actor($request), $request->url(), $request->sheet(), $request->mapping(), $request->boolean('auto_sync'));

        return new JsonResponse(['data' => self::present($result['import']), 'report' => $result['report']], 201);
    }

    public function update(UpdateSheetImportRequest $request, SheetImport $sheetImport): JsonResponse
    {
        $changes = $request->changes();
        if (isset($changes['mapping'])) {
            $changes['mapping'] = SheetsImportService::cleanMapping($changes['mapping'], count($sheetImport->headers));
        }

        return new JsonResponse(['data' => self::present($this->imports->update($sheetImport, $changes))]);
    }

    public function run(Request $request, SheetImport $sheetImport): JsonResponse
    {
        $report = $this->service->run($sheetImport, $this->actor($request));

        return new JsonResponse(['data' => self::present($sheetImport), 'report' => $report]);
    }

    /** @return array<string, mixed> */
    private static function present(SheetImport $import): array
    {
        return [
            'id' => $import->id,
            'spreadsheet_id' => $import->spreadsheet_id,
            'url' => 'https://docs.google.com/spreadsheets/d/'.$import->spreadsheet_id,
            'sheet' => $import->sheet,
            'headers' => $import->headers,
            'mapping' => (object) $import->mapping,
            'last_row' => $import->last_row,
            'auto_sync' => $import->auto_sync,
            'last_synced_at' => $import->last_synced_at?->toIso8601String(),
            'last_report' => $import->last_report,
        ];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return $actor;
    }
}
