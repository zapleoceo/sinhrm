<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Desk\Support\Sla;
use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;
use Illuminate\Support\Carbon;

/** Helpdesk cases opened in the range by category with SLA breaches (the same Sla calculation as the Desk module). */
final class DeskSlaReport extends AbstractReport
{
    public function __construct(private readonly ReportDataRepository $data) {}

    public function key(): string
    {
        return 'desk_sla';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::General;
    }

    public function filters(): array
    {
        return [self::FILTER_FROM, self::FILTER_TO];
    }

    public function columns(): array
    {
        return [
            ['key' => 'category', 'type' => 'string'],
            ['key' => 'cases', 'type' => 'number'],
            ['key' => 'open', 'type' => 'number'],
            ['key' => 'first_response_breached', 'type' => 'number'],
            ['key' => 'resolve_breached', 'type' => 'number'],
            ['key' => 'breached_pct', 'type' => 'percent'],
        ];
    }

    public function chart(): array
    {
        return ['label' => 'category', 'value' => 'cases'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->isAdmin();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        $range = self::range($filters, 90);
        $rows = [];
        foreach ($this->data->deskCases($range->from, $range->to) as $c) {
            $sla = Sla::of(
                Carbon::parse($c['created_at']),
                $c['first_response_hours'],
                $c['resolve_hours'],
                $c['first_response_at'] === null ? null : Carbon::parse($c['first_response_at']),
                $c['resolved_at'] === null ? null : Carbon::parse($c['resolved_at']),
                $ctx->now,
            );
            $row = $rows[$c['category']] ?? ['category' => $c['category'], 'cases' => 0, 'open' => 0, 'first_response_breached' => 0, 'resolve_breached' => 0, 'any' => 0];
            $row['cases']++;
            $row['open'] += $c['open'] ? 1 : 0;
            $row['first_response_breached'] += $sla['first_response_breached'] ? 1 : 0;
            $row['resolve_breached'] += $sla['resolve_breached'] ? 1 : 0;
            $row['any'] += $sla['first_response_breached'] || $sla['resolve_breached'] ? 1 : 0;
            $rows[$c['category']] = $row;
        }
        ksort($rows);

        return array_values(array_map(static function (array $r): array {
            $r['breached_pct'] = self::pct($r['any'], $r['cases']);
            unset($r['any']);

            return $r;
        }, $rows));
    }
}
