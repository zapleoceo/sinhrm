<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Services;

use App\Modules\Auth\Contracts\UserRepository;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\GoogleWorkspace\Contracts\SheetImportRepository;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use Illuminate\Support\Carbon;

/**
 * "sheets.sync" for POST /api/ops/jobs/run: re-runs the imports with auto_sync on (new rows only). Does nothing when
 * Sheets is not connected. The actor of an auto run is the user who saved the import (must still be active).
 */
final readonly class SheetsSyncJob implements ScheduledJob
{
    public function __construct(
        private SheetImportRepository $imports,
        private SheetsImportService $service,
        private GoogleConnectionStore $connections,
        private UserRepository $users,
    ) {}

    public function name(): string
    {
        return 'sheets.sync';
    }

    public function run(Carbon $now): array
    {
        if (! $this->connections->state(GoogleService::Sheets)->usable) {
            return ['skipped' => 'not_connected'];
        }
        $counts = ['imports' => 0, 'created' => 0, 'matched' => 0, 'errors' => 0, 'failed' => 0];
        foreach ($this->imports->autoSync() as $import) {
            $actor = $import->created_by === null ? null : $this->users->find($import->created_by);
            if ($actor === null || ! $actor->isActive()) {
                $counts['failed']++;

                continue;
            }
            try {
                $report = $this->service->run($import, $actor);
            } catch (GoogleException) {
                $counts['failed']++;

                continue;
            }
            $counts['imports']++;
            $counts['created'] += (int) $report['created'];
            $counts['matched'] += (int) $report['matched'];
            $counts['errors'] += count((array) $report['errors']);
        }

        return $counts;
    }
}
