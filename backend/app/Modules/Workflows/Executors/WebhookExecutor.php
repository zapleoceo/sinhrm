<?php

declare(strict_types=1);

namespace App\Modules\Workflows\Executors;

use App\Modules\Integrations\Support\OutboundUrlGuard;
use App\Modules\Workflows\Contracts\StepExecutor;
use App\Modules\Workflows\DTO\StepContext;
use App\Modules\Workflows\DTO\StepOutcome;
use App\Modules\Workflows\Enums\StepAction;
use App\Modules\Workflows\Support\WebhookSecrets;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * webhook: POST JSON to an https URL. SSRF guard first (OutboundUrlGuard: https, port 443, public IPs only),
 * redirects are not followed, timeout 10 s. Body signed with the template's key from the vault:
 * header X-SinHRM-Signature: sha256=<HMAC-SHA256(body)>. Results/logs carry codes only (never the URL or body).
 */
final readonly class WebhookExecutor implements StepExecutor
{
    public const int TIMEOUT_SECONDS = 10;

    public const string SIGNATURE_HEADER = 'X-SinHRM-Signature';

    public const string EVENT_HEADER = 'X-SinHRM-Event';

    public const string EVENT = 'workflow.step';

    public function __construct(private OutboundUrlGuard $guard, private WebhookSecrets $secrets) {}

    public function action(): StepAction
    {
        return StepAction::Webhook;
    }

    public function configRules(): array
    {
        return ['url' => ['required', 'url:https', 'max:500']];
    }

    public function execute(StepContext $context): StepOutcome
    {
        $url = $context->step->string('url');
        if ($url === null) {
            return StepOutcome::failed('invalid_url');
        }
        $blocked = $this->guard->check($url);
        if ($blocked !== null) {
            return StepOutcome::failed($blocked);
        }
        $secret = $this->secrets->get($context->run->template_id);
        if ($secret === null) {
            return StepOutcome::failed('missing_secret');
        }
        $body = (string) json_encode($this->payload($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders([
                    self::SIGNATURE_HEADER => WebhookSecrets::sign($body, $secret),
                    self::EVENT_HEADER => self::EVENT,
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException) {
            return StepOutcome::failed('connection_error');
        }

        return $response->successful()
            ? StepOutcome::done(['http_status' => $response->status()])
            : StepOutcome::failed('http_'.$response->status());
    }

    /** @return array<string, mixed> ids, the step and the employee's work data (no PII tier fields) */
    private function payload(StepContext $context): array
    {
        $employee = $context->employee;

        return [
            'event' => self::EVENT,
            'run_id' => $context->run->id,
            'run_step_id' => $context->runStep->id,
            'template' => ['id' => $context->run->template_id, 'name' => $context->run->template_name],
            'step' => ['title' => $context->step->title, 'action' => $context->step->action->value],
            'anchor_date' => $context->run->anchor_date->toDateString(),
            'employee' => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'work_email' => $employee->work_email,
                'hired_at' => $employee->hired_at->toDateString(),
                'fired_at' => $employee->fired_at?->toDateString(),
                'branch_id' => $employee->branch_id,
                'department_id' => $employee->department_id,
                'position_id' => $employee->position_id,
            ],
            'sent_at' => $context->now->toIso8601String(),
        ];
    }
}
