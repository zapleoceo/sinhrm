<?php

declare(strict_types=1);

namespace App\Modules\Auth\DTO;

/**
 * One kind of personal token a module issues: its name (one active token per name and user), the single Sanctum
 * ability it carries (TokenScopes maps abilities to API paths), lifetime and the log event prefix.
 */
final readonly class TokenKind
{
    public function __construct(
        public string $name,
        public string $ability,
        public int $ttlDays,
        /** e.g. "recruiting.extension_token" → recruiting.extension_token_issued / _revoked */
        public string $logEvent,
    ) {}
}
