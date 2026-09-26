<?php

declare(strict_types=1);

namespace App\Modules\GoogleWorkspace\DTO;

use App\Modules\GoogleWorkspace\Enums\GoogleService;
use Illuminate\Support\Carbon;

/**
 * What the API may show about a Google connection: never tokens, only status, account and granted scopes.
 * usable = status "connected" and a refresh token is stored.
 */
final readonly class ConnectionState
{
    /** @param  list<string>  $scopes */
    public function __construct(
        public GoogleService $service,
        public string $status,
        public bool $usable,
        public ?string $accountEmail,
        public array $scopes,
        public ?string $error,
        public ?Carbon $connectedAt,
    ) {}

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /** Gmail only: connected and the gmail.send scope was granted (older grants have gmail.readonly only). */
    public function canSend(): bool
    {
        return $this->service === GoogleService::Gmail && $this->usable && $this->hasScope(GoogleService::GMAIL_SEND_SCOPE);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'service' => $this->service->value,
            'integration_key' => $this->service->integrationKey(),
            'status' => $this->status,
            'connected' => $this->usable,
            'account_email' => $this->accountEmail,
            'scopes' => $this->scopes,
            'can_send' => $this->canSend(),
            'error' => $this->error,
            'connected_at' => $this->connectedAt?->toIso8601String(),
        ];
    }
}
