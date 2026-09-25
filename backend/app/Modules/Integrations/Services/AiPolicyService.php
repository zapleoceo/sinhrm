<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Services;

use App\Models\User;
use App\Modules\Integrations\Contracts\AiPolicy;
use App\Modules\Integrations\Contracts\IntegrationRepository;
use App\Modules\Integrations\Enums\LogLevel;

/**
 * Global AI switch, stored as integrations row "ai_policy" with settings {enabled: bool}.
 * Default OFF: no module may call AI providers until the owner approves models and prompts.
 */
final class AiPolicyService implements AiPolicy
{
    public const string KEY = 'ai_policy';

    public function __construct(private readonly IntegrationRepository $integrations) {}

    public function enabled(): bool
    {
        return ($this->integrations->find(self::KEY)->settings['enabled'] ?? false) === true;
    }

    public function set(User $actor, bool $enabled): bool
    {
        $row = $this->integrations->findOrCreate(self::KEY);
        $previous = ($row->settings['enabled'] ?? false) === true;
        $row->settings = ['enabled' => $enabled];
        $this->integrations->save($row);
        $this->integrations->log($row, LogLevel::Warning, $enabled ? 'ai_enabled' : 'ai_disabled', [
            'user_id' => $actor->id,
            'from' => $previous,
            'to' => $enabled,
        ]);

        return $enabled;
    }
}
