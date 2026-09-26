<?php

declare(strict_types=1);

namespace App\Modules\SafeSpeak\Services;

use App\Models\User;
use App\Modules\Core\Contracts\NavBadgeProvider;
use App\Modules\SafeSpeak\Enums\ReportStatus;
use App\Modules\SafeSpeak\Providers\SafeSpeakServiceProvider;
use Illuminate\Support\Facades\Gate;

/** "Safe Speak — вхідні" (/safe-speak/inbox, handlers only): new reports nobody has taken yet. */
final readonly class SafeSpeakNavBadges implements NavBadgeProvider
{
    public function __construct(private SafeSpeakService $safeSpeak) {}

    public function badges(User $user): array
    {
        if (! Gate::forUser($user)->allows(SafeSpeakServiceProvider::HANDLE)) {
            return [];
        }

        return ['safe_speak' => $this->safeSpeak->inbox(ReportStatus::New)->count()];
    }
}
