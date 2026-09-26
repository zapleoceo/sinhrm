<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Modules\Core\Support\MembershipDifferencing;
use PHPUnit\Framework\TestCase;

final class MembershipDifferencingTest extends TestCase
{
    public function test_symmetric_difference_counts_a_swap_as_two(): void
    {
        $this->assertSame(0, MembershipDifferencing::symmetricDifference(['a', 'b'], ['b', 'a']));
        $this->assertSame(2, MembershipDifferencing::symmetricDifference(['a', 'b', 'c'], ['a', 'b', 'd']));
        $this->assertFalse(MembershipDifferencing::allowed(2, 5));
        $this->assertTrue(MembershipDifferencing::allowed(5, 5));
        $this->assertTrue(MembershipDifferencing::allowed(0, 5));
    }

    public function test_a_hidden_release_is_not_a_base_so_steady_growth_is_not_hidden_forever(): void
    {
        $releases = [];
        $members = ['a', 'b', 'c', 'd', 'e'];
        for ($i = 0; $i < 7; $i++) {
            $releases[] = ['min' => 5, 'groups' => ['g' => $members]];
            $members[] = 'n'.$i; // one newcomer per period
        }
        $shown = array_map(static fn (array $v): bool => $v['g'], MembershipDifferencing::visibility($releases));
        // Shown: 1st; hidden while within 1..4 of it; 6th (5 newcomers later) shown again; 7th is 1 away from 6th.
        $this->assertSame([true, false, false, false, false, true, false], $shown);
    }

    public function test_every_earlier_shown_release_counts_not_only_the_previous_one(): void
    {
        $base = ['a', 'b', 'c', 'd', 'e', 'f'];
        $other = ['p', 'q', 'r', 's', 't', 'u'];
        $visible = MembershipDifferencing::visibility([
            ['min' => 5, 'groups' => ['g' => $base]],
            ['min' => 5, 'groups' => ['g' => $other]],
            ['min' => 5, 'groups' => ['g' => [...$base, 'x']]], // far from #2, but 1 away from #1
        ]);
        $this->assertSame([['g' => true], ['g' => true], ['g' => false]], $visible);
    }
}
