<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\Services;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\Overview\Contracts\DashboardNotices;

/** Superadmin sees "reconnect Google" when a refresh token was rejected (invalid_grant). */
final readonly class GoogleDashboardNotices implements DashboardNotices
{
    public function __construct(private GoogleConnectionStore $connections) {}

    public function for(User $user): array
    {
        if (! $user->hasRole(UserRole::Superadmin->value)) {
            return [];
        }
        $notices = [];
        foreach (GoogleService::cases() as $service) {
            if ($this->connections->state($service)->error === GoogleConnectionStore::RECONNECT_REQUIRED) {
                $notices[] = [
                    'code' => 'google_reconnect_required',
                    'level' => 'warning',
                    'params' => ['service' => $service->value],
                    'link' => '/admin/integrations',
                ];
            }
        }

        return $notices;
    }
}
