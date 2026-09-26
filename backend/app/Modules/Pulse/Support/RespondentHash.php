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
    /** @param  list<string>  $previousKeys  APP_PREVIOUS_KEYS, to re-key audience snapshots after a rotation */
    public function __construct(private string $key, private array $previousKeys = []) {}

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
    public function member(int $employeeId, ?string $key = null): string
    {
        $key ??= $this->key;
        if ($key === '') {
            throw new RuntimeException('member fingerprint needs APP_KEY');
        }

        return hash_hmac('sha256', 'member:'.$employeeId, $key);
    }

    /** Id of the key behind member fingerprints (not the key itself). */
    public function keyId(?string $key = null): string
    {
        return substr(hash('sha256', 'pulse-member-key:'.($key ?? $this->key)), 0, 16);
    }

    /** A previous APP_KEY with this key id, or null. */
    public function previousKey(string $keyId): ?string
    {
        foreach ($this->previousKeys as $key) {
            if ($key !== '' && $this->keyId($key) === $keyId) {
                return $key;
            }
        }

        return null;
    }
}
