<?php

declare(strict_types=1);

namespace Tests\Unit\TimeOff;

use App\Modules\Core\Contracts\UserNotifier;
use App\Modules\People\Models\Employee;
use App\Modules\TimeOff\Models\LeaveRequest;
use App\Modules\TimeOff\Models\LeaveType;
use App\Modules\TimeOff\Services\LeaveNotifications;
use Tests\TestCase;

/** Mails of the leave flow, extracted from LeaveRequestService: same recipients, subjects, texts and links. */
final class LeaveNotificationsTest extends TestCase
{
    /** @var list<array{int, string, string, string, string}> */
    private array $sent = [];

    public function test_manager_is_asked_unless_absent_or_the_author(): void
    {
        $notifications = new LeaveNotifications($this->notifier());

        $notifications->askManager($this->request(managerUserId: 7), 3);
        $notifications->askManager($this->request(managerUserId: 7), 7);
        $notifications->askManager($this->request(managerUserId: null), 3);

        $this->assertSame([[7, 'time-off', 'Погодити відпустку: Olena Test',
            'Olena Test просить «Vacation» 03.11.2026–05.11.2026. Потрібне ваше рішення.', '/timeoff/approvals']], $this->sent);
    }

    public function test_employee_learns_the_decision_with_the_comment(): void
    {
        $notifications = new LeaveNotifications($this->notifier());

        $notifications->decided($this->request(userId: 4, comment: 'ok'), true);
        $notifications->decided($this->request(userId: 4), false);
        $notifications->decided($this->request(userId: null), true);

        $this->assertSame([
            [4, 'time-off', 'Відпустку погоджено', "«Vacation» 03.11.2026–05.11.2026: погоджено.\nКоментар: ok", '/timeoff'],
            [4, 'time-off', 'Відпустку відхилено', '«Vacation» 03.11.2026–05.11.2026: відхилено.', '/timeoff'],
        ], $this->sent);
    }

    private function notifier(): UserNotifier
    {
        $sent = &$this->sent;

        return new class(static function (array $mail) use (&$sent): void {
            $sent[] = $mail;
        }) implements UserNotifier
        {

            /** @param  \Closure(array{int, string, string, string, string}): void  $record */
            public function __construct(private \Closure $record) {}

            public function notify(int $userId, string $module, string $subject, string $body, string $link): void
            {
                ($this->record)([$userId, $module, $subject, $body, $link]);
            }
        };
    }

    private function request(?int $userId = null, ?int $managerUserId = null, ?string $comment = null): LeaveRequest
    {
        $manager = $managerUserId === null ? null : (new Employee)->forceFill(['id' => 2, 'user_id' => $managerUserId]);
        $employee = (new Employee)->forceFill(['id' => 1, 'user_id' => $userId, 'full_name' => 'Olena Test'])->setRelation('manager', $manager);

        return (new LeaveRequest)->forceFill(['starts_on' => '2026-11-03', 'ends_on' => '2026-11-05', 'decision_comment' => $comment])
            ->setRelation('employee', $employee)
            ->setRelation('leaveType', (new LeaveType)->forceFill(['name' => 'Vacation']));
    }
}
