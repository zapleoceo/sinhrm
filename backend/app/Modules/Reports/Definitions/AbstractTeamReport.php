<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/**
 * Shared frame of the HR reports over the People scope: group «HR», available to whoever sees a team (admin — all,
 * manager — subtree + self). Filters stay per report.
 */
abstract class AbstractTeamReport extends AbstractReport
{
    public function group(): ReportGroup
    {
        return ReportGroup::Hr;
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->seesTeam();
    }
}
