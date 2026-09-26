<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Contracts\BuilderRepository;
use App\Modules\Reports\Contracts\Dataset;
use App\Modules\Reports\DTO\BuilderSpec;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Support\ReportRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Custom report builder. Everything the user sends is checked against the dataset whitelist: unknown dataset or
 * column, a PII column for a non-admin, an aggregate over a non-number, an unknown operator → 422. No raw SQL
 * ever comes from the request.
 */
final readonly class BuilderService
{
    public const int LIMIT = 5000;

    public const int MAX_COLUMNS = 20;

    public const int MAX_FILTERS = 10;

    public function __construct(private ReportRegistry $registry, private BuilderRepository $builder) {}

    /** @return list<array{key: string, columns: list<array{key: string, type: string, pii: bool}>}> datasets the user may use */
    public function datasets(ScopedContext $ctx): array
    {
        $out = [];
        foreach ($this->registry->datasets() as $d) {
            if (! $d->available($ctx)) {
                continue;
            }
            $columns = [];
            foreach ($d->columns() as $key => $c) {
                $pii = ($c['pii'] ?? false) === true;
                if (! $pii || $ctx->isAdmin()) {
                    $columns[] = ['key' => $key, 'type' => $c['type'], 'pii' => $pii];
                }
            }
            $out[] = ['key' => $d->key(), 'columns' => $columns];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input  {dataset, columns[], filters[{column, op, value}], group_by?, aggregate?{fn, column?}}
     *
     * @throws ValidationException
     */
    public function spec(ScopedContext $ctx, array $input): BuilderSpec
    {
        $dataset = $this->registry->dataset((string) ($input['dataset'] ?? ''));
        if ($dataset === null || ! $dataset->available($ctx)) {
            throw ValidationException::withMessages(['dataset' => 'unknown_dataset']);
        }
        $whitelist = $dataset->columns();
        $check = static function (mixed $key, string $field) use ($whitelist, $ctx): string {
            if (! is_string($key) || ! isset($whitelist[$key])) {
                throw ValidationException::withMessages([$field => 'unknown_column']);
            }
            if (($whitelist[$key]['pii'] ?? false) === true && ! $ctx->isAdmin()) {
                throw ValidationException::withMessages([$field => 'pii_forbidden']);
            }

            return $key;
        };

        $groupBy = isset($input['group_by']) && $input['group_by'] !== '' ? $check($input['group_by'], 'group_by') : null;
        $columns = [];
        foreach (array_values((array) ($input['columns'] ?? [])) as $i => $key) {
            $columns[] = $check($key, "columns.$i");
        }
        $columns = array_values(array_unique($columns));
        if ($groupBy === null && ($columns === [] || count($columns) > self::MAX_COLUMNS)) {
            throw ValidationException::withMessages(['columns' => 'invalid_columns']);
        }

        $filters = [];
        $rawFilters = array_values((array) ($input['filters'] ?? []));
        if (count($rawFilters) > self::MAX_FILTERS) {
            throw ValidationException::withMessages(['filters' => 'too_many_filters']);
        }
        foreach ($rawFilters as $i => $f) {
            $f = (array) $f;
            $column = $check($f['column'] ?? null, "filters.$i.column");
            $op = (string) ($f['op'] ?? '');
            if (! in_array($op, BuilderSpec::OPERATORS, true)) {
                throw ValidationException::withMessages(["filters.$i.op" => 'invalid_operator']);
            }
            $value = $f['value'] ?? null;
            if (! ($value === null || is_string($value) || is_int($value) || is_float($value)) || (is_string($value) && mb_strlen($value) > 200)) {
                throw ValidationException::withMessages(["filters.$i.value" => 'invalid_value']);
            }
            $filters[] = ['column' => $column, 'op' => $op, 'value' => $value];
        }

        $aggregate = null;
        $aggregateColumn = null;
        if ($groupBy !== null) {
            $agg = (array) ($input['aggregate'] ?? ['fn' => 'count']);
            $aggregate = (string) ($agg['fn'] ?? 'count');
            if (! in_array($aggregate, BuilderSpec::AGGREGATES, true)) {
                throw ValidationException::withMessages(['aggregate.fn' => 'invalid_aggregate']);
            }
            if ($aggregate !== 'count') {
                $aggregateColumn = $check($agg['column'] ?? null, 'aggregate.column');
                if ($whitelist[$aggregateColumn]['type'] !== Dataset::NUMBER) {
                    throw ValidationException::withMessages(['aggregate.column' => 'invalid_aggregate']);
                }
            }
        }

        return new BuilderSpec($dataset->key(), $columns, $filters, $groupBy, $aggregate, $aggregateColumn);
    }

    /** @return array{columns: list<string>, rows: list<array<string, scalar|null>>, truncated: bool} */
    public function run(ScopedContext $ctx, BuilderSpec $spec): array
    {
        $dataset = $this->registry->dataset($spec->dataset);
        assert($dataset !== null);
        $rows = $this->builder->run($spec, $dataset->columns(), $ctx, self::LIMIT);

        return [
            'columns' => $spec->groupBy !== null ? [$spec->groupBy, 'value'] : $spec->columns,
            'rows' => $rows,
            'truncated' => count($rows) >= self::LIMIT,
        ];
    }
}
