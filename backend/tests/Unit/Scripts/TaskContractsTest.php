<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Modules\Scripts\Contracts\TaskReader;
use App\Modules\Scripts\Contracts\TaskScheduler;
use App\Modules\Scripts\Services\TaskService;
use App\Modules\Workflows\Executors\CreateTaskExecutor;
use Tests\TestCase;

/** Other modules create, close and read tasks through TaskScheduler / TaskReader, both implemented by TaskService. */
final class TaskContractsTest extends TestCase
{
    public function test_both_contracts_resolve_to_the_task_service(): void
    {
        $this->assertInstanceOf(TaskService::class, $this->app->make(TaskScheduler::class));
        $this->assertInstanceOf(TaskService::class, $this->app->make(TaskReader::class));
    }

    public function test_a_consumer_built_by_the_container_gets_the_contract(): void
    {
        $this->assertInstanceOf(CreateTaskExecutor::class, $this->app->make(CreateTaskExecutor::class));
    }
}
