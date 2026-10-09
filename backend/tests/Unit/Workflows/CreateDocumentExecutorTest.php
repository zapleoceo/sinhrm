<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows;

use App\Modules\Documents\Contracts\DocumentTemplateRepository;
use App\Modules\People\Models\Employee;
use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepSnapshot;
use App\Modules\Workflows\Enums\RunStepStatus;
use App\Modules\Workflows\Enums\StepAction;
use App\Modules\Workflows\Executors\CreateDocumentExecutor;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Models\WorkflowRunStep;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Tests\TestCase;

/** The create_document step looks the template up through the Documents contract DocumentTemplateRepository (no DB). */
final class CreateDocumentExecutorTest extends TestCase
{
    public function test_a_missing_template_fails_the_step(): void
    {
        /** @var DocumentTemplateRepository&MockInterface $templates */
        $templates = $this->mock(DocumentTemplateRepository::class);
        $templates->expects('find')->with(42)->andReturnNull();
        $step = StepSnapshot::fromArray(['title' => 'Contract', 'action' => StepAction::CreateDocument->value, 'config' => ['document_template_id' => 42]]);
        $context = new StepContext(new WorkflowRun, new WorkflowRunStep, $step, new Employee, Carbon::parse('2026-10-08'));

        $outcome = $this->app->make(CreateDocumentExecutor::class)->execute($context);

        $this->assertSame(RunStepStatus::Failed, $outcome->status);
        $this->assertSame(['error' => 'document_template_missing'], $outcome->result);
    }
}
