<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Executors;

use App\Modules\GoogleWorkspace\Contracts\Mailer;
use App\Modules\GoogleWorkspace\DTO\OutgoingMail;
use App\Modules\GoogleWorkspace\Enums\MailerState;
use App\Modules\GoogleWorkspace\Exceptions\GoogleException;
use App\Modules\Workflows\Contracts\StepExecutor;
use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepOutcome;
use App\Modules\Workflows\Enums\StepAction;

/**
 * send_email_template: a plain-text e-mail to the employee (work e-mail, else personal) through the connected Gmail
 * (Mailer). Subject and body live in the step config (DB); "{{name}}" is replaced by the employee's full name.
 * Gmail not connected → skipped "not_connected"; connected read-only (no gmail.send) → skipped "reconnect_to_send";
 * no e-mail → skipped "no_recipient"; hourly send limit → failed "rate_limited" (the admin can retry later).
 */
final readonly class SendEmailTemplateExecutor implements StepExecutor
{
    public function __construct(private Mailer $mailer) {}

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
        $state = $this->mailer->state();
        if ($state !== MailerState::Ready) {
            return StepOutcome::skipped($state->value);
        }
        $employee = $context->employee;
        $to = $employee->work_email ?? $employee->personal_email;
        if ($to === null || $to === '') {
            return StepOutcome::skipped('no_recipient');
        }
        $fill = static fn (string $text): string => str_replace('{{name}}', $employee->full_name, $text);
        try {
            $sent = $this->mailer->send(new OutgoingMail(
                to: mb_strtolower($to),
                subject: $fill($context->step->string('subject') ?? $context->step->title),
                text: $fill($context->step->string('body') ?? ''),
                toName: $employee->full_name,
            ));
        } catch (GoogleException $e) {
            return StepOutcome::failed($e->errorCode === 'gmail_send_rate_limited' ? 'rate_limited' : $e->errorCode);
        }

        return StepOutcome::done(['message_id' => $sent->id]);
    }
}
