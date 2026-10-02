<?php

declare(strict_types=1);

namespace App\Modules\Overview\Services;

use App\Modules\Overview\Contracts\DashboardRepository;
use App\Modules\Recruiting\DTO\Scope;
use App\Modules\Recruiting\Enums\StageKind;
use Illuminate\Support\Carbon;

/**
 * Two captions under the home-page funnel, computed only from the stage history (stage_changes) of applications in
 * the user's scope; null when there is too little data, never an estimate.
 *
 * - bottleneck: the pair of adjacent route stages (closed stages skipped) with the lowest conversion, among
 *   applications whose route started in the last PERIOD_DAYS. Conversion A → B = applications that reached B /
 *   applications that reached A and are already decided at A (went further, or were closed/hired). Applications
 *   still active on A are not counted yet. A pair needs at least MIN_SAMPLE decided applications. Pipelines with
 *   the same stage names are summed (like the funnel).
 * - offer_path: average days from the start of the route (first stage change = the response) to the first entry
 *   into a "hire"-kind stage (the offer and further), over applications that made that entry in the last
 *   PERIOD_DAYS; at least MIN_OFFER_OBSERVATIONS of them.
 */
final readonly class FunnelInsightsService
{
    public const int PERIOD_DAYS = 90;

    public const int MIN_SAMPLE = 10;

    public const int MIN_OFFER_OBSERVATIONS = 3;

    public function __construct(private DashboardRepository $dashboard) {}

    /**
     * @return array{period_days: int, min_sample: int, min_offer_observations: int,
     *     bottleneck: array{from: string, to: string, from_kind: string, to_kind: string, conversion: int, passed: int, decided: int}|null,
     *     offer_path: array{days: int, observations: int}|null}
     */
    public function build(Scope $scope, Carbon $now): array
    {
        $since = $now->copy()->subDays(self::PERIOD_DAYS);

        return [
            'period_days' => self::PERIOD_DAYS,
            'min_sample' => self::MIN_SAMPLE,
            'min_offer_observations' => self::MIN_OFFER_OBSERVATIONS,
            'bottleneck' => self::bottleneck($this->dashboard->routeStages(), $this->dashboard->reachedStages($scope, $since)),
            'offer_path' => self::offerPath($this->dashboard->offerPaths($scope, $since, $now)),
        ];
    }

    /**
     * @param  list<array{pipeline_id: int, name: string, kind: string, position: int}>  $stages  route stages, ordered
     * @param  list<array{pipeline_id: int, max_position: int, active: bool}>  $reached
     * @return array{from: string, to: string, from_kind: string, to_kind: string, conversion: int, passed: int, decided: int}|null
     */
    public static function bottleneck(array $stages, array $reached, int $minSample = self::MIN_SAMPLE): ?array
    {
        $byPipeline = [];
        foreach ($stages as $s) {
            if ($s['kind'] !== StageKind::Closed->value) {
                $byPipeline[$s['pipeline_id']][] = $s;
            }
        }
        $appsByPipeline = [];
        foreach ($reached as $r) {
            $appsByPipeline[$r['pipeline_id']][] = $r;
        }

        /** @var array<string, array{from: string, to: string, from_kind: string, to_kind: string, position: int, passed: int, decided: int}> $pairs */
        $pairs = [];
        foreach ($byPipeline as $pipelineId => $route) {
            $apps = $appsByPipeline[$pipelineId] ?? [];
            for ($i = 0, $n = count($route) - 1; $i < $n; $i++) {
                [$a, $b] = [$route[$i], $route[$i + 1]];
                $passed = 0;
                $decided = 0;
                foreach ($apps as $app) {
                    if ($app['max_position'] >= $b['position']) {
                        $passed++;
                        $decided++;
                    } elseif ($app['max_position'] >= $a['position'] && ! $app['active']) {
                        $decided++;
                    }
                }
                $key = $a['name']."\u{2192}".$b['name'];
                $pairs[$key] ??= ['from' => $a['name'], 'to' => $b['name'], 'from_kind' => $a['kind'], 'to_kind' => $b['kind'], 'position' => $a['position'], 'passed' => 0, 'decided' => 0];
                $pairs[$key]['passed'] += $passed;
                $pairs[$key]['decided'] += $decided;
            }
        }

        $worst = null;
        foreach ($pairs as $p) {
            if ($p['decided'] < $minSample) {
                continue;
            }
            $rate = $p['passed'] / $p['decided'];
            if ($worst === null || $rate < $worst['rate'] || ($rate === $worst['rate'] && $p['position'] < $worst['pair']['position'])) {
                $worst = ['rate' => $rate, 'pair' => $p];
            }
        }
        if ($worst === null) {
            return null;
        }
        $p = $worst['pair'];

        return [
            'from' => $p['from'],
            'to' => $p['to'],
            'from_kind' => $p['from_kind'],
            'to_kind' => $p['to_kind'],
            'conversion' => (int) round($worst['rate'] * 100),
            'passed' => $p['passed'],
            'decided' => $p['decided'],
        ];
    }

    /**
     * @param  list<array{started_at: Carbon, offer_at: Carbon}>  $paths
     * @return array{days: int, observations: int}|null
     */
    public static function offerPath(array $paths, int $minObservations = self::MIN_OFFER_OBSERVATIONS): ?array
    {
        $days = [];
        foreach ($paths as $p) {
            if ($p['offer_at']->gte($p['started_at'])) {
                $days[] = $p['started_at']->diffInSeconds($p['offer_at']) / 86400;
            }
        }
        if (count($days) < $minObservations) {
            return null;
        }

        return ['days' => (int) round(array_sum($days) / count($days)), 'observations' => count($days)];
    }
}
