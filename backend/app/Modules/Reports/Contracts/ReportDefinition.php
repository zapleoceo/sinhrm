<?php

declare(strict_types=1);

namespace App\Modules\Reports\Contracts;

use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/**
 * One ready report of the catalog (Open/Closed: a new report = a new class tagged in ReportsServiceProvider).
 * query() must only read through the existing scopes of ScopedContext and return plain rows whose keys are the
 * declared columns. Labels are translated by the UI (reports.names.<key>, reports.columns.<column>).
 */
interface ReportDefinition
{
    /** Filter types understood by the runner (validation and the UI controls). */
    public const string FILTER_FROM = 'from';

    public const string FILTER_TO = 'to';

    public const string FILTER_BRANCH = 'branch_id';

    public const string FILTER_WEEKS = 'weeks';

    public const string FILTER_PERIOD = 'period';

    public function key(): string;

    public function group(): ReportGroup;

    /** @return list<string> filter keys (FILTER_*) */
    public function filters(): array;

    /** @return list<array{key: string, type: string}> type: string | number | percent | date */
    public function columns(): array;

    /** @return array{label: string, value: string}|null columns for the CSS bar chart */
    public function chart(): ?array;

    public function available(ScopedContext $ctx): bool;

    /**
     * @param  array<string, int|string>  $filters  validated, only this report's filters (from, to: Y-m-d; branch_id, weeks: int; period)
     * @return list<array<string, scalar|null>>
     */
    public function rows(ScopedContext $ctx, array $filters): array;
}
