<?php

declare(strict_types=1);

namespace App\Modules\Audit\Providers;

use App\Models\User;
use App\Modules\Ai\Models\AiPromptVersion;
use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Audit\Contracts\AuditLogRepository;
use App\Modules\Audit\Privacy\AuditPersonalData;
use App\Modules\Audit\Repositories\EloquentAuditLogRepository;
use App\Modules\Audit\Services\AuditRetentionJob;
use App\Modules\Audit\Services\AuditService;
use App\Modules\Audit\Support\AuditObserver;
use App\Modules\Audit\Support\AuditPolicy;
use App\Modules\Audit\Support\SecretAuditObserver;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Contracts\PersonalDataProvider;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Documents\Models\Document;
use App\Modules\HiringRequests\Models\HiringApproval;
use App\Modules\HiringRequests\Models\HiringRequest;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Integrations\Models\IntegrationSecret;
use App\Modules\People\Models\Employee;
use App\Modules\Recruiting\Models\Application;
use App\Modules\Recruiting\Models\Candidate;
use App\Modules\Recruiting\Models\Vacancy;
use App\Modules\TimeOff\Models\LeaveRequest;
use App\Modules\Workflows\Models\WorkflowTemplate;
use Illuminate\Contracts\Foundation\Application as App;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

final class AuditServiceProvider extends ModuleServiceProvider
{
    /** Ability guarding the global audit log: active superadmin only. */
    public const string VIEW_AUDIT = 'view-audit-log';

    /**
     * Tracked models → entity type in the log. New model = one line here.
     * Safe Speak and Pulse are refused by AuditPolicy (anonymity), even if added here by mistake.
     *
     * @var array<class-string<Model>, string>
     */
    public const array TRACKED = [
        User::class => 'user',
        Integration::class => 'integration',
        AiPromptVersion::class => 'ai_prompt_version',
        Employee::class => 'employee',
        Vacancy::class => 'vacancy',
        Candidate::class => 'candidate',
        Application::class => 'application',
        LeaveRequest::class => 'leave_request',
        Document::class => 'document',
        HiringRequest::class => 'hiring_request',
        HiringApproval::class => 'hiring_approval',
        WorkflowTemplate::class => 'workflow_template',
    ];

    protected string $prefix = 'audit';

    public function register(): void
    {
        $this->app->bind(AuditLogRepository::class, EloquentAuditLogRepository::class);
        $this->app->singleton(AuditPolicy::class);
        $this->app->bind(AuditLogger::class, AuditService::class);
        $this->app->bind(AuditObserver::class, fn (App $app): AuditObserver => new AuditObserver(
            $app->make(AuditLogger::class),
            self::TRACKED,
        ));
        $this->app->tag([AuditRetentionJob::class], ScheduledJob::class);
        $this->app->tag([AuditPersonalData::class], PersonalDataProvider::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::define(self::VIEW_AUDIT, fn (User $user): bool => $user->isActive()
            && $user->hasRole(UserRole::Superadmin->value));

        $policy = $this->app->make(AuditPolicy::class);
        foreach (array_keys(self::TRACKED) as $model) {
            $policy->assertTrackable($model);
            $model::observe(AuditObserver::class);
        }
        IntegrationSecret::observe(SecretAuditObserver::class);
    }
}
