<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Modules\Recruiting\Support\RejectionBreakdown;
use PHPUnit\Framework\TestCase;

final class RejectionBreakdownTest extends TestCase
{
    /** @return list<array{reject_reason_id: int|null, name: string|null, stage_id: int|null, stage_name: string|null, stage_position: int|null, user_id: int|null, user_name: string|null, count: int}> */
    private function rows(): array
    {
        $row = static fn (?int $reason, ?int $stage, ?int $position, ?int $user, int $count): array => [
            'reject_reason_id' => $reason, 'name' => $reason === null ? null : 'R'.$reason,
            'stage_id' => $stage, 'stage_name' => $stage === null ? null : 'S'.$stage, 'stage_position' => $position,
            'user_id' => $user, 'user_name' => $user === null ? null : 'U'.$user, 'count' => $count,
        ];

        return [
            $row(1, 10, 2, 7, 1),
            $row(1, 11, 1, 8, 1),
            $row(1, 10, 2, 8, 2),
            $row(1, null, null, null, 1),
            $row(null, 11, 1, 7, 1),
            $row(2, 11, 1, 9, 1),
        ];
    }

    public function test_by_stage_sums_and_orders_with_unknown_last(): void
    {
        $out = RejectionBreakdown::byStage($this->rows());

        $this->assertSame([
            ['reject_reason_id' => 1, 'name' => 'R1', 'stage_id' => 10, 'stage_name' => 'S10', 'count' => 3],
            ['reject_reason_id' => 1, 'name' => 'R1', 'stage_id' => 11, 'stage_name' => 'S11', 'count' => 1],
            ['reject_reason_id' => 1, 'name' => 'R1', 'stage_id' => null, 'stage_name' => null, 'count' => 1],
            ['reject_reason_id' => 2, 'name' => 'R2', 'stage_id' => 11, 'stage_name' => 'S11', 'count' => 1],
            ['reject_reason_id' => null, 'name' => null, 'stage_id' => 11, 'stage_name' => 'S11', 'count' => 1],
        ], $out);
        $this->assertSame(7, array_sum(array_column($out, 'count')));
    }

    public function test_by_recruiter_names_only_recruiting_users(): void
    {
        // 7 holds a recruiting role, 8 and 9 do not (hidden, merged per reason), null = no author.
        $out = RejectionBreakdown::byRecruiter($this->rows(), [7]);

        $this->assertSame([
            ['reject_reason_id' => 1, 'name' => 'R1', 'recruiter_id' => null, 'recruiter_name' => null, 'recruiter_state' => 'hidden', 'count' => 3],
            ['reject_reason_id' => 1, 'name' => 'R1', 'recruiter_id' => 7, 'recruiter_name' => 'U7', 'recruiter_state' => 'user', 'count' => 1],
            ['reject_reason_id' => 1, 'name' => 'R1', 'recruiter_id' => null, 'recruiter_name' => null, 'recruiter_state' => 'unassigned', 'count' => 1],
            ['reject_reason_id' => 2, 'name' => 'R2', 'recruiter_id' => null, 'recruiter_name' => null, 'recruiter_state' => 'hidden', 'count' => 1],
            ['reject_reason_id' => null, 'name' => null, 'recruiter_id' => 7, 'recruiter_name' => 'U7', 'recruiter_state' => 'user', 'count' => 1],
        ], $out);
        $this->assertSame([], RejectionBreakdown::byRecruiter([], []));
        $this->assertSame([], RejectionBreakdown::byStage([]));
    }
}
