<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Executors;

use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Services\GoogleConnectionStore;
use App\Modules\Workflows\Contracts\StepExecutor;
use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepOutcome;
use App\Modules\Workflows\Enums\StepAction;

/**
 * send_email_template: e-mail through the connected Gmail. Gmail not connected → skipped "not_connected".
 * The Gmail connection is READ-ONLY on purpose (scope gmail.readonly, GoogleService::scopes) — sending needs the
 * gmail.send scope and the owner's decision, so a connected mailbox gives skipped "send_not_supported" for now.
 */
final readonly class SendEmailTemplateExecutor implements StepExecutor
{
    public function __construct(private GoogleConnectionStore $connections) {}

    public function action(): StepAction
    {
        return StepAction::SendEmailTemplate;
    }

    public function configRules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
        ];
    }

    public function execute(StepContext $context): StepOutcome
    {
        if (! $this->connections->state(GoogleService::Gmail)->usable) {
            return StepOutcome::skipped('not_connected');
        }

        return StepOutcome::skipped('send_not_supported');
    }
}
