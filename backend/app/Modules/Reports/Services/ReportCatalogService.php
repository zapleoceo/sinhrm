<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Contracts\ReportDefinition;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;
use App\Modules\Reports\Support\ReportRegistry;
use Illuminate\Support\Facades\Validator;

/**
 * The ready-report catalog: what the user may run (grouped), running one with validated filters.
 * A report the user may not run answers 404 (its existence is not a secret, but its data is).
 */
final readonly class ReportCatalogService
{
    public function __construct(private ReportRegistry $registry) {}

    /** @return list<array{group: string, reports: list<array<string, mixed>>}> */
    public function catalog(ScopedContext $ctx): array
    {
        $groups = [];
        foreach (ReportGroup::cases() as $group) {
            $reports = array_values(array_map(
                static fn (ReportDefinition $r): array => self::describe($r),
                array_filter($this->registry->reports(), static fn (ReportDefinition $r): bool => $r->group() === $group && $r->available($ctx)),
            ));
            if ($reports !== []) {
                $groups[] = ['group' => $group->value, 'reports' => $reports];
            }
        }

        return $groups;
    }

    public function find(ScopedContext $ctx, string $key): ReportDefinition
    {
        $report = $this->registry->report($key);
        if ($report === null || ! $report->available($ctx)) {
            abort(404);
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $input  raw query parameters; only the report's own filters are read
     * @return array{report: array<string, mixed>, filters: array<string, mixed>, rows: list<array<string, scalar|null>>}
     */
    public function run(ScopedContext $ctx, ReportDefinition $report, array $input): array
    {
        $filters = self::validate($report, $input);

        return ['report' => self::describe($report), 'filters' => $filters, 'rows' => $report->rows($ctx, $filters)];
    }

    /** @return array<string, mixed> */
    public static function describe(ReportDefinition $r): array
    {
        return ['key' => $r->key(), 'group' => $r->group()->value, 'filters' => $r->filters(), 'columns' => $r->columns(), 'chart' => $r->chart()];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, int|string> only the report's own, non-empty filters
     */
    private static function validate(ReportDefinition $report, array $input): array
    {
        $rules = [
            ReportDefinition::FILTER_FROM => ['nullable', 'date_format:Y-m-d'],
            ReportDefinition::FILTER_TO => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            ReportDefinition::FILTER_BRANCH => ['nullable', 'integer', 'min:1'],
            ReportDefinition::FILTER_WEEKS => ['nullable', 'integer', 'min:1', 'max:52'],
            ReportDefinition::FILTER_PERIOD => ['nullable', 'string', 'max:7'],
        ];
        $own = array_intersect_key($rules, array_flip($report->filters()));
        $data = Validator::make(array_intersect_key($input, $own), $own)->validate();
        $out = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if ($value === null || $value === '') {
                continue;
            }
            $out[$key] = in_array($key, [ReportDefinition::FILTER_BRANCH, ReportDefinition::FILTER_WEEKS], true) ? (int) $value : (string) $value;
        }

        return $out;
    }
}
