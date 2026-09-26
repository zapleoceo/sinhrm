<?php

declare(strict_types=1);

namespace App\Modules\Pulse\Support;

use RuntimeException;

/**
 * One-way respondent token: HMAC-SHA256 keyed by APP_KEY over "<wave salt>:<employee id>".
 * It lets the server refuse a second answer from the same person without storing who answered. The salt lives only
 * while the wave is open; once it is wiped (wave closed) nobody — not even with the database and APP_KEY — can
 * recompute a person's token.
 */
final readonly class RespondentHash
{
    public function __construct(private string $key) {}

    public function for(?string $salt, int $employeeId): string
    {
        if ($salt === null || $salt === '' || $this->key === '') {
            throw new RuntimeException('respondent hash needs an open wave salt and APP_KEY');
        }

        return hash_hmac('sha256', $salt.':'.$employeeId, $this->key);
    }

    /**
     * Wave-independent fingerprint of an audience member (survey_wave_members): equal in every wave, so two
     * audiences can be compared, but it is never stored next to an answer.
     */
    public function member(int $employeeId): string
    {
        if ($this->key === '') {
            throw new RuntimeException('member fingerprint needs APP_KEY');
        }

        return hash_hmac('sha256', 'member:'.$employeeId, $this->key);
    }
}
