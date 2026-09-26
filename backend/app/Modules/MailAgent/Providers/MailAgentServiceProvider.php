<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Providers;

use App\Modules\Ai\Providers\AiServiceProvider;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\Core\Contracts\ScheduledJob;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\MailAgent\Ai\MailClassificationAiHandler;
use App\Modules\MailAgent\Ai\MailClassificationPrompt;
use App\Modules\MailAgent\Contracts\MailClassifier;
use App\Modules\MailAgent\Contracts\MailLogRepository;
use App\Modules\MailAgent\Contracts\SenderRuleRepository;
use App\Modules\MailAgent\Contracts\UnknownSenderRepository;
use App\Modules\MailAgent\Parsers\DjinniParser;
use App\Modules\MailAgent\Parsers\GenericParser;
use App\Modules\MailAgent\Parsers\RobotaUaParser;
use App\Modules\MailAgent\Parsers\WorkUaParser;
use App\Modules\MailAgent\Repositories\EloquentMailLogRepository;
use App\Modules\MailAgent\Repositories\EloquentSenderRuleRepository;
use App\Modules\MailAgent\Repositories\EloquentUnknownSenderRepository;
use App\Modules\MailAgent\Services\MailSyncJob;
use App\Modules\MailAgent\Services\RulesMailClassifier;
use App\Modules\MailAgent\Support\ParserRegistry;
use Illuminate\Contracts\Foundation\Application;

/**
 * Mail agent: reads the connected Gmail box (GoogleWorkspace), classifies mail by sender rules (AI only suggests), turns
 * job-board mail into candidates/applications/tasks, candidate mail into touchpoints. Routes under /api/mail.
 */
final class MailAgentServiceProvider extends ModuleServiceProvider
{
    protected string $moduleIcon = 'mark_email_unread';

    protected string $moduleGroup = 'admin';

    /** Every route of the module is behind the manage-integrations gate (superadmin only). */
    protected ?array $defaultRoles = [UserRole::Superadmin];

    /** Container tag of MailParser classes. New parser = one class + one line here. */
    public const string PARSERS_TAG = 'mail.parsers';

    protected string $prefix = 'mail';

    public function register(): void
    {
        $this->app->tag([WorkUaParser::class, RobotaUaParser::class, DjinniParser::class, GenericParser::class], self::PARSERS_TAG);
        $this->app->bind(ParserRegistry::class, fn (Application $app): ParserRegistry => new ParserRegistry($app->tagged(self::PARSERS_TAG)));

        $this->app->bind(SenderRuleRepository::class, EloquentSenderRuleRepository::class);
        $this->app->bind(UnknownSenderRepository::class, EloquentUnknownSenderRepository::class);
        $this->app->bind(MailLogRepository::class, EloquentMailLogRepository::class);
        // Rules decide; AiMailClassifier only adds a suggestion to the unknown-senders queue (Ai module).
        $this->app->bind(MailClassifier::class, RulesMailClassifier::class);

        $this->app->tag([MailSyncJob::class], ScheduledJob::class);
        $this->app->tag([MailClassificationAiHandler::class], AiServiceProvider::HANDLERS_TAG);
        $this->app->tag([MailClassificationPrompt::class], AiServiceProvider::PROMPTS_TAG);
    }
}
