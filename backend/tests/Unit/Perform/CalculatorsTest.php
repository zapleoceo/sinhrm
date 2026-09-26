<?php

declare(strict_types=1);

namespace Tests\Unit\Perform;

use App\Modules\Perform\Enums\ReviewType;
use App\Modules\Perform\Support\ListItems;
use App\Modules\Perform\Support\ObjectiveProgress;
use App\Modules\Perform\Support\ReviewResults;
use PHPUnit\Framework\TestCase;

/** Pure Perform calculators: objective progress, review aggregation (suppression, anonymity), list item ids. */
final class CalculatorsTest extends TestCase
{
    public function test_key_result_progress_handles_increase_decrease_and_flat_targets(): void
    {
        $this->assertSame(0.5, ObjectiveProgress::keyResult(['start' => 0, 'target' => 10, 'current' => 5]));
        $this->assertSame(0.5, ObjectiveProgress::keyResult(['start' => 10, 'target' => 0, 'current' => 5]), 'decrease metric');
        $this->assertSame(1.0, ObjectiveProgress::keyResult(['start' => 0, 'target' => 10, 'current' => 25]), 'clamped');
        $this->assertSame(0.0, ObjectiveProgress::keyResult(['start' => 0, 'target' => 10, 'current' => -3]), 'clamped');
        $this->assertSame(1.0, ObjectiveProgress::keyResult(['start' => 5, 'target' => 5, 'current' => 5]));
        $this->assertSame(0.0, ObjectiveProgress::keyResult(['start' => 5, 'target' => 5, 'current' => 4]));
    }

    public function test_objective_progress_is_a_weighted_mean(): void
    {
        $this->assertSame(0, ObjectiveProgress::of([]));
        $this->assertSame(75, ObjectiveProgress::of([
            ['start' => 0, 'target' => 10, 'current' => 10, 'weight' => 1],
            ['start' => 0, 'target' => 10, 'current' => 5, 'weight' => 1],
        ]));
        $this->assertSame(83, ObjectiveProgress::of([
            ['start' => 0, 'target' => 10, 'current' => 10, 'weight' => 2],
            ['start' => 0, 'target' => 10, 'current' => 5, 'weight' => 1],
        ]));
        $this->assertSame(0, ObjectiveProgress::of([['start' => 0, 'target' => 10, 'current' => 10, 'weight' => 0]]));
    }

    public function test_review_results_suppress_small_peer_groups_and_hide_names(): void
    {
        $types = [ReviewType::Self, ReviewType::Manager, ReviewType::Peer];
        $competencies = [['id' => 1, 'name' => 'Communication', 'max' => 5]];
        $row = static fn (string $type, int $reviewer, int $rating, ?string $comment = null): array => [
            'type' => $type, 'reviewer_id' => $reviewer, 'reviewer_name' => 'R'.$reviewer, 'competency_id' => 1, 'rating' => $rating, 'comment' => $comment,
        ];
        $rows = [$row('self', 1, 5), $row('manager', 2, 3, 'Good'), $row('peer', 3, 2, 'zz'), $row('peer', 4, 4, 'aa')];

        $two = ReviewResults::aggregate($types, $competencies, $rows, true);
        $this->assertEquals(['reviewers' => null, 'suppressed' => true], $two['groups']['peer']);
        $this->assertNull($two['competencies'][0]['scores']['peer']);
        $this->assertSame(3.0, $two['competencies'][0]['average'], 'self excluded, suppressed peers excluded');
        $this->assertSame(['manager'], array_column($two['comments'], 'type'));

        $rows[] = $row('peer', 5, 3);
        $three = ReviewResults::aggregate($types, $competencies, $rows, true);
        $this->assertEquals(['reviewers' => 3, 'suppressed' => false], $three['groups']['peer']);
        $this->assertSame(3.0, $three['competencies'][0]['scores']['peer']);
        $this->assertSame(['Good', 'aa', 'zz'], array_column($three['comments'], 'text'), 'peer comments sorted by text');
        $this->assertSame(['R2', null, null], array_column($three['comments'], 'author'));

        $named = ReviewResults::aggregate($types, $competencies, $rows, false);
        $this->assertSame(['R2', 'R4', 'R3'], array_column($named['comments'], 'author'), 'non-anonymous cycle names peers');
    }

    public function test_list_items_keep_valid_ids_and_generate_the_rest(): void
    {
        $items = ListItems::normalize([
            ['id' => 'kr1', 'text' => 'A', 'extra' => 'dropped'],
            ['id' => 'kr1', 'text' => 'Duplicate id'],
            ['id' => 'bad id!', 'text' => 'B'],
            ['text' => null],
        ], ['text' => '', 'done' => false]);

        $this->assertSame(['id' => 'kr1', 'text' => 'A', 'done' => false], $items[0]);
        $this->assertNotSame('kr1', $items[1]['id']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{10}$/', $items[2]['id']);
        $this->assertSame('', $items[3]['text']);
        $this->assertCount(4, array_unique(array_column($items, 'id')));
    }
}
