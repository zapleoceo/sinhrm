<?php

declare(strict_types=1);

namespace Tests\Unit\People;

use App\Modules\People\DTO\PeopleContext;
use App\Modules\People\Support\ReportingTree;
use PHPUnit\Framework\TestCase;

/** ReportingTree (subtree walk) and PeopleContext (access flags) — pure, no DB. */
final class PeopleScopeTest extends TestCase
{
    public function test_descendants_are_direct_and_indirect_reports(): void
    {
        // 1 ← 2 ← 3, 2 ← 4, 5 alone
        $map = [1 => null, 2 => 1, 3 => 2, 4 => 2, 5 => null];

        $this->assertSame([2, 3, 4], ReportingTree::descendants($map, 1));
        $this->assertSame([3, 4], ReportingTree::descendants($map, 2));
        $this->assertSame([], ReportingTree::descendants($map, 3));
        $this->assertSame([], ReportingTree::descendants($map, 99));
        $this->assertSame([1 => [2], 2 => [3, 4]], ReportingTree::children($map));
    }

    public function test_cycles_in_bad_data_do_not_loop(): void
    {
        $this->assertSame([2, 3], ReportingTree::descendants([1 => 3, 2 => 1, 3 => 2], 1));
        $this->assertSame([], ReportingTree::descendants([1 => 1], 1));
    }

    public function test_context_flags(): void
    {
        $manager = new PeopleContext(10, false, 1, [2, 3]);
        $admin = new PeopleContext(11, true, null, []);
        $nobody = new PeopleContext(12, false, null, []);

        $this->assertSame(['job' => true, 'pii' => false, 'decide' => true, 'manage' => false, 'self' => false], $manager->flags(3));
        $this->assertSame(['job' => true, 'pii' => true, 'decide' => false, 'manage' => false, 'self' => true], $manager->flags(1));
        $this->assertSame(['job' => false, 'pii' => false, 'decide' => false, 'manage' => false, 'self' => false], $manager->flags(7));
        $this->assertTrue($manager->isManager());
        $this->assertSame([1, 2, 3], $manager->visibleIds());
        $this->assertNull($admin->visibleIds());
        $this->assertTrue($admin->canSeePii(7));
        $this->assertSame([], $nobody->visibleIds());
        $this->assertFalse($nobody->isManager());
    }
}
