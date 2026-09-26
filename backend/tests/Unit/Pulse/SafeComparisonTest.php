<?php

declare(strict_types=1);

namespace Tests\Unit\Pulse;

use App\Modules\Pulse\Support\SafeComparison;
use PHPUnit\Framework\TestCase;

final class SafeComparisonTest extends TestCase
{
    public function test_small_nonzero_difference_is_withheld(): void
    {
        $this->assertTrue(SafeComparison::allowed(10, 10, 5));
        $this->assertFalse(SafeComparison::allowed(11, 10, 5));
        $this->assertFalse(SafeComparison::allowed(10, 14, 5));
        $this->assertTrue(SafeComparison::allowed(10, 15, 5));
    }
}
