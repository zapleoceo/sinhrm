<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Pulse\Services\MoodService;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/**
 * Team mood per COMPLETED week (reuses Pulse MoodService::team: admins — everyone or a branch, managers — their
 * subtree; weeks with fewer people than the minimum group are suppressed; comments are not part of the report).
 */
final class MoodTrendReport extends AbstractReport
{
    public const int DEFAULT_WEEKS = 12;

    public function __construct(private readonly MoodService $mood) {}

    public function key(): string
    {
        return 'mood_trend';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Performance;
    }

    public function filters(): array
    {
        return [self::FILTER_WEEKS, self::FILTER_BRANCH];
    }

    public function columns(): array
    {
        return [['key' => 'week', 'type' => 'date'], ['key' => 'respondents', 'type' => 'number'], ['key' => 'average', 'type' => 'number']];
    }

    public function chart(): array
    {
        return ['label' => 'week', 'value' => 'average'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->seesTeam();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        $weeks = isset($filters['weeks']) ? (int) $filters['weeks'] : self::DEFAULT_WEEKS;
        // A manager's branch filter is ignored by MoodService (their subtree is the scope).
        $team = $this->mood->team($ctx->user, $weeks, $ctx->isAdmin() ? self::branch($filters) : null, null, $ctx->now);

        return array_map(static fn (array $w): array => [
            'week' => (string) $w['week_start'],
            'respondents' => $w['respondents'],
            'average' => $w['average'],
        ], $team['weeks']);
    }
}
