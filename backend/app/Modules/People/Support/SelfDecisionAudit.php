<?php

declare(strict_types=1);

namespace App\Modules\People\Support;

use App\Modules\Audit\Contracts\AuditLogger;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\People\DTO\PeopleContext;
use Psr\Log\LoggerInterface;

/**
 * Break-glass trail (PeopleContext::canDecideOrBreakGlass): a sole superadmin who decides their own request, salary
 * or leave balance leaves an audit entry on their employee record with meta.self_decision = true, so the next
 * administrator sees every such decision in "Журнал дій". No-op for an ordinary decision.
 */
final readonly class SelfDecisionAudit
{
    public function __construct(
        private AuditLogger $audit,
        private LoggerInterface $log,
    ) {}

    /** @param  string  $operation  e.g. timeoff.leave_approved; $refId — id of the decided record */
    public function record(PeopleContext $ctx, int $employeeId, string $operation, int $refId): void
    {
        if (! $ctx->isSelfDecision($employeeId)) {
            return;
        }
        $meta = ['self_decision' => true, 'operation' => $operation, 'ref_id' => $refId];
        $this->audit->record('employee', $employeeId, AuditAction::Updated, null, $meta, $ctx->userId);
        $this->log->warning('people.self_decision', ['employee' => $employeeId, 'operation' => $operation, 'ref' => $refId, 'by' => $ctx->userId]);
    }
}
