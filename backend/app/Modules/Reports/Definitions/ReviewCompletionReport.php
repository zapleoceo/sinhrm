<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/** Review cycles: forms assigned vs submitted (managers: forms about their people only). No ratings, no names. */
final class ReviewCompletionReport extends AbstractReport
{
    public function __construct(private readonly ReportDataRepository $data) {}

    public function key(): string
    {
        return 'review_completion';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Performance;
    }

    public function columns(): array
    {
        return [
            ['key' => 'cycle', 'type' => 'string'],
            ['key' => 'status', 'type' => 'string'],
            ['key' => 'assigned', 'type' => 'number'],
            ['key' => 'submitted', 'type' => 'number'],
            ['key' => 'completion_pct', 'type' => 'percent'],
        ];
    }

    public function chart(): array
    {
        return ['label' => 'cycle', 'value' => 'completion_pct'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->seesTeam();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        return array_map(static fn (array $c): array => [
            'cycle' => $c['cycle'],
            'status' => $c['status'],
            'assigned' => $c['total'],
            'submitted' => $c['submitted'],
            'completion_pct' => self::pct($c['submitted'], $c['total']),
        ], $this->data->reviewCycles($ctx->employeeIds()));
    }
}
