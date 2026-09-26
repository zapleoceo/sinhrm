<?php

declare(strict_types=1);

namespace App\Modules\Reports\Definitions;

use App\Modules\Pulse\Contracts\ResponseRepository;
use App\Modules\Pulse\Support\Enps;
use App\Modules\Reports\Contracts\ReportDataRepository;
use App\Modules\Reports\DTO\ScopedContext;
use App\Modules\Reports\Enums\ReportGroup;

/**
 * eNPS per CLOSED wave (Pulse reveals results only after closing). A wave with fewer eNPS answers than its minimum
 * group is suppressed: no score and no count, exactly like the Pulse results page. Company level only (admins).
 */
final class EnpsTrendReport extends AbstractReport
{
    public const int WAVES = 24;

    public function __construct(private readonly ReportDataRepository $data, private readonly ResponseRepository $responses) {}

    public function key(): string
    {
        return 'enps_trend';
    }

    public function group(): ReportGroup
    {
        return ReportGroup::Performance;
    }

    public function columns(): array
    {
        return [
            ['key' => 'survey', 'type' => 'string'],
            ['key' => 'closed_on', 'type' => 'date'],
            ['key' => 'responses', 'type' => 'number'],
            ['key' => 'enps', 'type' => 'number'],
        ];
    }

    public function chart(): array
    {
        return ['label' => 'closed_on', 'value' => 'enps'];
    }

    public function available(ScopedContext $ctx): bool
    {
        return $ctx->isAdmin();
    }

    public function rows(ScopedContext $ctx, array $filters): array
    {
        $rows = [];
        foreach ($this->data->closedEnpsWaves(self::WAVES) as $w) {
            $values = [];
            foreach ($this->responses->answersOf($w['wave_id']) as $r) {
                $v = $r['answers'][$w['question_id']] ?? null;
                if (is_int($v) || (is_numeric($v) && (string) (int) $v === (string) $v)) {
                    $values[] = (int) $v;
                }
            }
            $suppressed = count($values) < $w['min_group'];
            $rows[] = [
                'survey' => $w['title'],
                'closed_on' => $w['ends_at'],
                'responses' => $suppressed ? null : count($values),
                'enps' => $suppressed ? null : Enps::calculate($values)['score'],
            ];
        }

        return $rows;
    }
}
