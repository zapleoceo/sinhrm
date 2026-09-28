<?php

declare(strict_types=1);

namespace App\Modules\Auth\DTO;

use Carbon\CarbonInterface;

/** What a settings page shows about a personal token; the plaintext is returned only once, at issue time. */
final readonly class PersonalTokenStatus
{
    public function __construct(
        public bool $active,
        public ?CarbonInterface $createdAt = null,
        public ?CarbonInterface $lastUsedAt = null,
        public ?CarbonInterface $expiresAt = null,
        public ?string $plainText = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'active' => $this->active,
            'created_at' => $this->createdAt?->toIso8601String(),
            'last_used_at' => $this->lastUsedAt?->toIso8601String(),
            'expires_at' => $this->expiresAt?->toIso8601String(),
        ];

        return $this->plainText === null ? $data : $data + ['token' => $this->plainText];
    }
}
