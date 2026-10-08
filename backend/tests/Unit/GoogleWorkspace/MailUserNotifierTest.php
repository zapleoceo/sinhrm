<?php

declare(strict_types=1);

namespace Tests\Unit\GoogleWorkspace;

use App\Models\User;
use App\Modules\Auth\Contracts\UserRepository;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\GoogleWorkspace\Contracts\Mailer;
use App\Modules\GoogleWorkspace\Services\MailUserNotifier;
use Mockery\MockInterface;
use Tests\TestCase;

/** MailUserNotifier finds the recipient through Auth's UserRepository contract; nobody to notify → no mail (no DB). */
final class MailUserNotifierTest extends TestCase
{
    public function test_unknown_or_blocked_recipient_gets_no_mail(): void
    {
        $blocked = new User(['email' => 'blocked@example.test', 'name' => 'Blocked']);
        $blocked->status = UserStatus::Blocked;
        $blocked->approval_emails = true;
        /** @var UserRepository&MockInterface $users */
        $users = $this->mock(UserRepository::class);
        $users->expects('find')->with(404)->andReturnNull();
        $users->expects('find')->with(7)->andReturn($blocked);
        /** @var Mailer&MockInterface $mailer */
        $mailer = $this->mock(Mailer::class);
        $mailer->expects('send')->never();
        $mailer->expects('state')->never();

        $notifier = $this->app->make(MailUserNotifier::class, ['frontendUrl' => 'https://app.example.test']);
        $notifier->notify(404, 'people', 'Subject', 'Body', '/people');
        $notifier->notify(7, 'people', 'Subject', 'Body', '/people');
    }
}
