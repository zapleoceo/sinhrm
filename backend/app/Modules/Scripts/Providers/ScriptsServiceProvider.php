<?php

declare(strict_types=1);

namespace App\Modules\Scripts\Providers;

use App\Models\User;
use App\Modules\Ai\Providers\AiServiceProvider;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Recruiting\Contracts\TouchpointEvaluations;
use App\Modules\Recruiting\Events\TouchpointRecorded;
use App\Modules\Recruiting\Services\RecruitingScope;
use App\Modules\Scripts\Ai\ScriptEvaluationAiHandler;
use App\Modules\Scripts\Ai\ScriptEvaluationPrompt;
use App\Modules\Scripts\Contracts\EvaluationRepository;
use App\Modules\Scripts\Contracts\ScriptEvaluator;
use App\Modules\Scripts\Contracts\ScriptRepository;
use App\Modules\Scripts\Contracts\TaskRepository;
use App\Modules\Scripts\Listeners\EvaluateRecordedTouch;
use App\Modules\Scripts\Models\Task;
use App\Modules\Scripts\Policies\TaskPolicy;
use App\Modules\Scripts\Repositories\EloquentEvaluationRepository;
use App\Modules\Scripts\Repositories\EloquentScriptRepository;
use App\Modules\Scripts\Repositories\EloquentTaskRepository;
use App\Modules\Scripts\Services\FollowupJob;
use App\Modules\Scripts\Services\RulesScriptEvaluator;
use App\Modules\Scripts\Support\ScriptTouchpointEvaluations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * Scripts (versioned recruiter scripts, evaluation of touches, templates) and recruiter tasks (follow-ups).
 * Routes at the /api root: scripts, candidates/{id}/templates, touchpoints/{id}/evaluation, reports/scripts, tasks.
 */
final class ScriptsServiceProvider extends ModuleServiceProvider
{
    /** Create/edit/publish/activate scripts: superadmin, admin. */
    public const string MANAGE = 'scripts-manage';

    public function register(): void
    {
        $this->app->bind(ScriptRepository::class, EloquentScriptRepository::class);
        $this->app->bind(EvaluationRepository::class, EloquentEvaluationRepository::class);
        $this->app->bind(TaskRepository::class, EloquentTaskRepository::class);
        // The always-available engine; EvaluationService adds the AI one when AI is available (Ai module).
        $this->app->bind(ScriptEvaluator::class, RulesScriptEvaluator::class);
        // Evaluation summaries on Recruiting timeline items.
        $this->app->bind(TouchpointEvaluations::class, ScriptTouchpointEvaluations::class);
        $this->app->tag([FollowupJob::class], ScheduledJob::class);
        // Applies deferred AI evaluations (Ai module, ai.poll).
        $this->app->tag([ScriptEvaluationAiHandler::class], AiServiceProvider::HANDLERS_TAG);
        $this->app->tag([ScriptEvaluationPrompt::class], AiServiceProvider::PROMPTS_TAG);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Task::class, TaskPolicy::class);
        Gate::define(self::MANAGE, fn (User $user): bool => $this->app->make(RecruitingScope::class)->canManage($user));

        Event::listen(TouchpointRecorded::class, EvaluateRecordedTouch::class);
    }
}
