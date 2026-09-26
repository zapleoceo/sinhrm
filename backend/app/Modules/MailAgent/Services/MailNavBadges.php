<?php

declare(strict_types=1);

namespace App\Modules\MailAgent\Services;

use App\Models\User;
use App\Modules\Core\Contracts\NavBadgeProvider;
use App\Modules\Integrations\Providers\IntegrationsServiceProvider;
use App\Modules\MailAgent\Contracts\UnknownSenderRepository;
use App\Modules\MailAgent\Http\Controllers\MailAgentController;
use Illuminate\Support\Facades\Gate;

/** "Пошта" (/admin/mail, superadmin): unknown senders waiting for a rule; capped like the page's list. */
final readonly class MailNavBadges implements NavBadgeProvider
{
    public function __construct(private UnknownSenderRepository $unknown) {}

    public function badges(User $user): array
    {
        if (! Gate::forUser($user)->allows(IntegrationsServiceProvider::MANAGE_INTEGRATIONS)) {
            return [];
        }

        return ['mail_unknown' => min($this->unknown->count(), MailAgentController::LIST_LIMIT)];
    }
}
