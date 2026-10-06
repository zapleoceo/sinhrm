<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Modules\Scripts\Enums\TaskSource;
use App\Modules\Scripts\Enums\TaskType;
use PHPUnit\Framework\TestCase;

/** The "Мої задачі" source filter: the termination handover task belongs to the offboarding group (workflows). */
final class TaskTypeSourceTest extends TestCase
{
    public function test_termination_handover_is_listed_under_workflows(): void
    {
        $this->assertSame('exit_handover', TaskType::TerminationHandover->value);
        $this->assertSame(TaskSource::Workflows, TaskType::TerminationHandover->source());
        $this->assertContains('exit_handover', TaskSource::Workflows->typeValues());
        $this->assertNotContains('exit_handover', TaskSource::TimeOff->typeValues());
    }
}
