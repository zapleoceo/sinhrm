<?php

declare(strict_types=1);

namespace Tests\Unit\Overview;

use App\Modules\Overview\Services\FunnelInsightsService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/** The two funnel captions of the home page: formulas and the "too little data" thresholds. */
final class FunnelInsightsServiceTest extends TestCase
{
    /** @return list<array{pipeline_id: int, name: string, kind: string, position: int}> */
    private static function route(int $pipelineId): array
    {
        return [
            ['pipeline_id' => $pipelineId, 'name' => 'New', 'kind' => 'attract', 'position' => 1],
            ['pipeline_id' => $pipelineId, 'name' => 'Interview', 'kind' => 'select', 'position' => 2],
            ['pipeline_id' => $pipelineId, 'name' => 'Offer', 'kind' => 'hire', 'position' => 4],
        ];
    }

    /** @return list<array{pipeline_id: int, max_position: int, active: bool}> */
    private static function apps(int $pipelineId, int $maxPosition, bool $active, int $n): array
    {
        return array_fill(0, $n, ['pipeline_id' => $pipelineId, 'max_position' => $maxPosition, 'active' => $active]);
    }

    public function test_bottleneck_is_the_lowest_decided_conversion_between_adjacent_route_stages(): void
    {
        $stages = [...self::route(1), ['pipeline_id' => 1, 'name' => 'Rejected', 'kind' => 'closed', 'position' => 3]];
        $reached = [
            ...self::apps(1, 4, false, 4),   // reached the offer (position 4; the closed stage 3 is skipped)
            ...self::apps(1, 2, false, 8),   // stopped at the interview
            ...self::apps(1, 1, false, 3),   // stopped at the start
            ...self::apps(1, 2, true, 20),   // still on the interview: not decided yet, ignored for Interview → Offer
        ];

        $this->assertSame(
            ['from' => 'Interview', 'to' => 'Offer', 'from_kind' => 'select', 'to_kind' => 'hire', 'conversion' => 33, 'passed' => 4, 'decided' => 12],
            FunnelInsightsService::bottleneck($stages, $reached),
        );
    }

    public function test_pairs_below_the_minimum_sample_are_skipped_and_pipelines_with_same_names_are_summed(): void
    {
        $stages = [...self::route(1), ...self::route(2)];
        // Pipeline 1 alone: New → Interview 1/6 (too small); together with pipeline 2: 5/12.
        $reached = [...self::apps(1, 2, false, 1), ...self::apps(1, 1, false, 5), ...self::apps(2, 2, true, 4), ...self::apps(2, 1, false, 2)];

        $this->assertNull(FunnelInsightsService::bottleneck($stages, array_slice($reached, 0, 6)));
        $this->assertSame(
            ['from' => 'New', 'to' => 'Interview', 'from_kind' => 'attract', 'to_kind' => 'select', 'conversion' => 42, 'passed' => 5, 'decided' => 12],
            FunnelInsightsService::bottleneck($stages, $reached),
        );
        $this->assertNull(FunnelInsightsService::bottleneck($stages, []));
    }

    public function test_offer_path_is_the_average_in_days_and_needs_three_observations(): void
    {
        $path = static fn (string $from, string $to): array => ['started_at' => Carbon::parse($from), 'offer_at' => Carbon::parse($to)];

        $this->assertNull(FunnelInsightsService::offerPath([$path('2026-09-01', '2026-09-11'), $path('2026-09-01', '2026-09-21')]));
        $this->assertSame(
            ['days' => 13, 'observations' => 3],
            FunnelInsightsService::offerPath([$path('2026-09-01', '2026-09-11'), $path('2026-09-01', '2026-09-21'), $path('2026-09-01 00:00', '2026-09-09 12:00')]),
        );
    }
}
