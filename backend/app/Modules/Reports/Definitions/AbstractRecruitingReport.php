<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/**
 * Shared frame of the Recruiting reports: group «Recruiting», a from/to period filter and availability for any active
 * user (the data is narrowed by the Recruiting scope of the source service).
 */
abstract class AbstractRecruitingReport extends AbstractReport
{
    public function group(): ReportGroup
    {
        return ReportGroup::Recruiting;
    }

    public function filters(): array
    {
        return [self::FILTER_FROM, self::FILTER_TO];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->user->isActive();
    }
}
