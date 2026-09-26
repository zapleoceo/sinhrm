<?php

declare(strict_types=1);

namespace App\Modules\Reports\Repositories;

use App\Modules\Reports\Contracts\BuilderRepository;
use App\Modules\Reports\Contracts\Dataset;
use App\Modules\Reports\DTO\BuilderSpec;
use App\Modules\Reports\DTO\ScopedContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Executes builder specs. Only whitelisted expressions (Dataset::columns) and fixed aliases (their keys, validated as
 * identifiers by the service) reach SQL; every user value is a bound parameter. Each dataset's base query applies
 * the same scope as the module it comes from.
 */
final class QueryBuilderRepository implements BuilderRepository
{
    public function run(BuilderSpec $spec, array $columns, ScopedContext $ctx, int $limit): array
    {
        $query = $this->base($spec->dataset, $ctx);
        foreach ($spec->filters as $f) {
            $this->filter($query, $columns[$f['column']], $f['op'], $f['value']);
        }

        if ($spec->groupBy !== null) {
            $expr = $columns[$spec->groupBy]['expr'];
            $value = match ($spec->aggregate) {
                'sum' => 'sum('.$columns[(string) $spec->aggregateColumn]['expr'].')',
                'avg' => 'avg('.$columns[(string) $spec->aggregateColumn]['expr'].')',
                default => 'count(*)',
            };
            $rows = $query->groupBy(DB::raw($expr))
                ->selectRaw("{$expr} as {$spec->groupBy}, {$value} as value")
                ->orderByRaw($expr)
                ->limit($limit)
                ->get();

            return $rows->map(fn (object $r): array => [
                $spec->groupBy => $this->cast($r->{$spec->groupBy}, $columns[$spec->groupBy]['type']),
                'value' => $r->value === null ? null : round((float) $r->value, 2),
            ])->values()->all();
        }

        foreach ($spec->columns as $key) {
            $query->selectRaw("{$columns[$key]['expr']} as {$key}");
        }
        $rows = $query->orderByRaw($columns[$spec->columns[0]]['expr'])->limit($limit)->get();

        return $rows->map(function (object $r) use ($spec, $columns): array {
            $out = [];
            foreach ($spec->columns as $key) {
                $out[$key] = $this->cast($r->{$key}, $columns[$key]['type']);
            }

            return $out;
        })->values()->all();
    }

    /** The scoped FROM/JOIN part of each dataset (aliases match the Dataset expressions). */
    private function base(string $dataset, ScopedContext $ctx): Builder
    {
        $ids = $ctx->employeeIds();
        $branches = $ctx->recruiting->branchIds;

        return match ($dataset) {
            'employees' => DB::table('employees as e')
                ->leftJoin('branches as b', 'b.id', '=', 'e.branch_id')
                ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
                ->leftJoin('positions as p', 'p.id', '=', 'e.position_id')
                ->leftJoin('employees as m', 'm.id', '=', 'e.manager_id')
                ->when($ids !== null, static fn (Builder $q) => $q->whereIn('e.id', $ids ?? [])),
            'leave_requests' => DB::table('leave_requests as lr')
                ->join('employees as e', 'e.id', '=', 'lr.employee_id')
                ->join('leave_types as t', 't.id', '=', 'lr.leave_type_id')
                ->leftJoin('branches as b', 'b.id', '=', 'e.branch_id')
                ->when($ids !== null, static fn (Builder $q) => $q->whereIn('lr.employee_id', $ids ?? [])),
            'applications' => DB::table('applications as a')
                ->join('candidates as c', 'c.id', '=', 'a.candidate_id')
                ->join('vacancies as v', 'v.id', '=', 'a.vacancy_id')
                ->join('pipeline_stages as s', 's.id', '=', 'a.stage_id')
                ->leftJoin('reject_reasons as r', 'r.id', '=', 'a.reject_reason_id')
                ->leftJoin('branches as b', 'b.id', '=', 'v.branch_id')
                ->when($branches !== null, static fn (Builder $q) => $q->whereIn('v.branch_id', $branches ?? [])),
            'touchpoints' => DB::table('touchpoints as t')
                ->leftJoin('candidates as c', 'c.id', '=', 't.candidate_id')
                ->leftJoin('users as u', 'u.id', '=', 't.author_id')
                ->leftJoin('branches as b', 'b.id', '=', 't.branch_id')
                ->when($branches !== null, static fn (Builder $q) => $q->where(static fn (Builder $w) => $w
                    ->whereIn('t.branch_id', $branches ?? [])
                    ->orWhere('t.author_id', $ctx->user->id))),
            'assets' => DB::table('assets as x')
                ->leftJoin('asset_types as y', 'y.id', '=', 'x.type_id')
                ->leftJoin('employees as e', 'e.id', '=', 'x.employee_id'),
            default => throw new LogicException('Unknown dataset '.$dataset),
        };
    }

    /** @param  array{expr: string, type: string, pii?: bool}  $column */
    private function filter(Builder $query, array $column, string $op, string|int|float|null $value): void
    {
        $expr = $column['expr'];
        if ($value === null || $value === '') {
            $op === 'neq' ? $query->whereRaw("{$expr} is not null") : $query->whereRaw("{$expr} is null");

            return;
        }
        if ($op === 'contains') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower((string) $value)).'%';
            $query->whereRaw("lower(cast({$expr} as varchar(255))) like ? escape '!'", [$pattern]);

            return;
        }
        $bound = $column['type'] === Dataset::NUMBER ? (is_numeric($value) ? $value + 0 : 0) : (string) $value;
        if ($column['type'] === Dataset::DATE) {
            // Dates compare by day, whether the column is a date or a timestamp.
            $day = substr((string) $value, 0, 10);
            match ($op) {
                'eq' => $query->whereRaw("{$expr} >= ? and {$expr} <= ?", [$day, $day.' 23:59:59']),
                'neq' => $query->whereRaw("({$expr} < ? or {$expr} > ?)", [$day, $day.' 23:59:59']),
                'gt' => $query->whereRaw("{$expr} > ?", [$day.' 23:59:59']),
                'gte' => $query->whereRaw("{$expr} >= ?", [$day]),
                'lt' => $query->whereRaw("{$expr} < ?", [$day]),
                default => $query->whereRaw("{$expr} <= ?", [$day.' 23:59:59']),
            };

            return;
        }
        $sqlOp = ['eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$op];
        $query->whereRaw("{$expr} {$sqlOp} ?", [$bound]);
    }

    private function cast(mixed $value, string $type): string|int|float|null
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            Dataset::NUMBER => is_bool($value) ? (int) $value : (is_numeric($value) ? $value + 0 : null),
            Dataset::DATE => substr((string) $value, 0, 10),
            default => is_bool($value) ? (int) $value : (string) $value,
        };
    }
}
