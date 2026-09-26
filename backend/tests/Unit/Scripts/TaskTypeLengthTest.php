<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Modules\Scripts\Enums\TaskType;
use PHPUnit\Framework\TestCase;

/** tasks.type is varchar(16): Postgres rejects longer values (SQLite would silently accept them). */
final class TaskTypeLengthTest extends TestCase
{
    public function test_every_task_type_fits_the_column(): void
    {
        foreach (TaskType::cases() as $type) {
            $this->assertLessThanOrEqual(16, strlen($type->value), $type->value);
        }
    }
}
