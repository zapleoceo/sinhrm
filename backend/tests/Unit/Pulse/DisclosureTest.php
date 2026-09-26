<?php

declare(strict_types=1);

namespace Tests\Unit\Pulse;

use App\Modules\Perform\Enums\ReviewType;
use App\Modules\Perform\Support\ReviewResults;
use App\Modules\Pulse\Support\Participation;
use App\Modules\Pulse\Support\SafeSegments;
use PHPUnit\Framework\TestCase;

/** Disclosure controls: safe segments (no subtraction), coarse participation, 360 results before closing. */
final class DisclosureTest extends TestCase
{
    /** @return list<int> */
    private static function n(int $count): array
    {
        return array_fill(0, $count, 1);
    }

    public function test_safe_segments_keep_every_hidden_remainder_empty_or_large(): void
    {
        // Small group hidden; the rest (0) is empty → big groups stay.
        $this->assertSame([1, 2], array_keys(SafeSegments::allowed([1 => self::n(5), 2 => self::n(6)], 5)));
        // 6 + 5 shown would leave 2 hidden → the smallest shown one (5) is hidden too.
        $this->assertSame([1], array_keys(SafeSegments::allowed([1 => self::n(6), 2 => self::n(5), 3 => self::n(2)], 5)));
        // Answers without a segment count in the total: 5 shown of 7 would leave 2 → nothing shown.
        $this->assertSame([], SafeSegments::allowed([1 => self::n(5)], 5, 7));
        // One segment equal to the whole wave: its complement is empty, nothing new is revealed.
        $this->assertSame([1], array_keys(SafeSegments::allowed([1 => self::n(5)], 5, 5)));
        $this->assertSame([], SafeSegments::allowed([1 => self::n(4)], 5));
    }

    public function test_participation_is_coarse(): void
    {
        $this->assertSame(['responded_bucket' => '0–4', 'responded_percent' => 10], Participation::of(4, 40));
        $this->assertSame(['responded_bucket' => '5–9', 'responded_percent' => 20], Participation::of(9, 40));
        $this->assertSame(['responded_bucket' => '10–19', 'responded_percent' => null], Participation::of(10, 0));
        $this->assertSame('20+', Participation::bucket(250));
    }

    public function test_protected_review_groups_stay_hidden_until_the_cycle_is_final(): void
    {
        $rows = [];
        foreach ([3, 4, 5] as $reviewer) {
            $rows[] = ['type' => 'peer', 'reviewer_id' => $reviewer, 'reviewer_name' => 'R'.$reviewer, 'competency_id' => 1, 'rating' => 4, 'comment' => 'Text '.$reviewer];
        }
        $args = [[ReviewType::Peer], [['id' => 1, 'name' => 'C', 'max' => 5]], $rows, true];

        $live = ReviewResults::aggregate(...[...$args, false]);
        $this->assertSame(['reviewers' => null, 'suppressed' => true, 'submitted' => '3–4'], $live['groups']['peer']);
        $this->assertNull($live['competencies'][0]['scores']['peer']);
        $this->assertSame([], $live['comments']);

        $final = ReviewResults::aggregate(...[...$args, true]);
        $this->assertSame(['reviewers' => 3, 'suppressed' => false], $final['groups']['peer']);
        $this->assertSame(4.0, $final['competencies'][0]['scores']['peer']);
    }
}
