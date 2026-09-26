<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Providers;

use App\Models\User;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\People\Events\EmployeeHired;
use App\Modules\People\Events\EmployeeTerminated;
use App\Modules\People\Services\PeopleScope;
use App\Modules\Scripts\Events\TaskCompleted;
use App\Modules\Workflows\Contracts\AssigneeDirectory;
use App\Modules\Workflows\Contracts\WorkflowRunRepository;
use App\Modules\Workflows\Contracts\WorkflowTemplateRepository;
use App\Modules\Workflows\Executors\AddCalendarEventExecutor;
use App\Modules\Workflows\Executors\AssignBuddyExecutor;
use App\Modules\Workflows\Executors\CreateDocumentExecutor;
use App\Modules\Workflows\Executors\CreateTaskExecutor;
use App\Modules\Workflows\Executors\NotifyManagerExecutor;
use App\Modules\Workflows\Executors\RequestFormExecutor;
use App\Modules\Workflows\Executors\SendEmailTemplateExecutor;
use App\Modules\Workflows\Executors\StartWorkflowExecutor;
use App\Modules\Workflows\Executors\UploadDocumentRequestExecutor;
use App\Modules\Workflows\Executors\WebhookExecutor;
use App\Modules\Workflows\Listeners\CompleteStepFromTask;
use App\Modules\Workflows\Listeners\StartOffboardingWorkflows;
use App\Modules\Workflows\Listeners\StartOnboardingWorkflows;
use App\Modules\Workflows\Repositories\EloquentAssigneeDirectory;
use App\Modules\Workflows\Repositories\EloquentWorkflowRunRepository;
use App\Modules\Workflows\Repositories\EloquentWorkflowTemplateRepository;
use App\Modules\Workflows\Services\WorkflowTickJob;
use App\Modules\Workflows\Support\ExecutorRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * Workflows: onboarding/offboarding templates with steps, runs (snapshots), step executors (Open/Closed, tagged),
 * triggers from People events, the "workflows.tick" job. Routes: /api/workflows/*.
 */
final class WorkflowsServiceProvider extends ModuleServiceProvider
{
    protected string $moduleIcon = 'checklist';

    protected string $moduleGroup = 'people';

    /** Templates, start / cancel runs, retry steps: superadmin, admin (HR). */
    public const string MANAGE = 'workflows-manage';

    /** Container tag of StepExecutor classes. */
    public const string EXECUTORS_TAG = 'workflows.executors';

    protected string $prefix = 'workflows';

    public function register(): void
    {
        $this->app->bind(WorkflowTemplateRepository::class, EloquentWorkflowTemplateRepository::class);
        $this->app->bind(WorkflowRunRepository::class, EloquentWorkflowRunRepository::class);
        $this->app->bind(AssigneeDirectory::class, EloquentAssigneeDirectory::class);

        // New action = one executor class + one line here (and the enum case).
        $this->app->tag([
            CreateTaskExecutor::class,
            RequestFormExecutor::class,
            SendEmailTemplateExecutor::class,
            AddCalendarEventExecutor::class,
            CreateDocumentExecutor::class,
            UploadDocumentRequestExecutor::class,
            WebhookExecutor::class,
            StartWorkflowExecutor::class,
            NotifyManagerExecutor::class,
            AssignBuddyExecutor::class,
        ], self::EXECUTORS_TAG);
        $this->app->bind(ExecutorRegistry::class, fn (Application $app): ExecutorRegistry => new ExecutorRegistry(
            $app->tagged(self::EXECUTORS_TAG),
        ));
        $this->app->tag([WorkflowTickJob::class], ScheduledJob::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::define(self::MANAGE, fn (User $user): bool => $this->app->make(PeopleScope::class)->isAdmin($user));

        Event::listen(EmployeeHired::class, StartOnboardingWorkflows::class);
        Event::listen(EmployeeTerminated::class, StartOffboardingWorkflows::class);
        Event::listen(TaskCompleted::class, CompleteStepFromTask::class);
    }
}
