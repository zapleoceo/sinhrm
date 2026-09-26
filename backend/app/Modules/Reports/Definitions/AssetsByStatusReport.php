<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/** The asset register in numbers: assets and their cost by status and type. */
final class AssetsByStatusReport extends AbstractReport
{
    public function __construct(private readonly ReportDataRepository $data) {}

    public function key(): string
    {
        return 'assets_by_status';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::General;
    }

    public function columns(): array
    {
        return [['key' => 'status', 'type' => 'string'], ['key' => 'type', 'type' => 'string'], ['key' => 'assets', 'type' => 'number'], ['key' => 'cost', 'type' => 'number']];
    }

    public function chart(): array
    {
        return ['label' => 'status', 'value' => 'assets'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->isAdmin();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        return array_map(static fn (array $r): array => ['type' => $r['type'] ?? '—'] + $r, $this->data->assetsByStatus());
    }
}
