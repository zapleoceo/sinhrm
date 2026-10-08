<?php

declare(strict_types=1);

namespace App\Modules\TimeOff\Services;

use App\Modules\Core\Contracts\UserNotifier;
use App\Modules\TimeOff\Models\LeaveRequest;

/**
 * Mails of the leave flow (UserNotifier, module "time-off"): the manager is asked to decide a new pending request,
 * the employee learns the decision. People without a login get nothing; the author is never asked about their own.
 */
final readonly class LeaveNotifications
{
    public function __construct(private UserNotifier $notifier) {}

    /** A new pending request → the employee's manager (unless the manager created it). */
    public function askManager(LeaveRequest $request, int $actorId): void
    {
        $managerUser = $request->employee->manager?->user_id;
        if ($managerUser === null || $managerUser === $actorId) {
            return;
        }
        $this->notifier->notify($managerUser, LeaveRequestService::MODULE, 'Погодити відпустку: '.$request->employee->full_name,
            sprintf('%s просить «%s» %s. Потрібне ваше рішення.', $request->employee->full_name, $request->leaveType->name, self::span($request)),
            '/timeoff/approvals');
    }

    /** Approved or rejected → the employee, with the decision comment. */
    public function decided(LeaveRequest $request, bool $approved): void
    {
        $userId = $request->employee->user_id;
        if ($userId === null) {
            return;
        }
        $this->notifier->notify($userId, LeaveRequestService::MODULE, $approved ? 'Відпустку погоджено' : 'Відпустку відхилено',
            sprintf('«%s» %s: %s.', $request->leaveType->name, self::span($request), $approved ? 'погоджено' : 'відхилено')
            .($request->decision_comment !== null ? "\nКоментар: ".$request->decision_comment : ''),
            '/timeoff');
    }

    private static function span(LeaveRequest $request): string
    {
        return $request->starts_on->format('d.m.Y').'–'.$request->ends_on->format('d.m.Y');
    }
}
