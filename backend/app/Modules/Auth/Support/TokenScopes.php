<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Where a bearer token is accepted (session/cookie auth is untouched). Modules register a path pattern and the one
 * ability that opens it (Recruiting: api/clipper/* → "clipper", Assistant: api/mcp → "mcp"); every other path needs
 * the "full" ability, which is never issued today — so a scoped token gets 401 anywhere outside its own paths.
 * Registered once as Sanctum's token check in AuthServiceProvider::boot.
 */
final class TokenScopes
{
    public const string FULL = 'full';

    /** @var array<string, string> path pattern (Request::is syntax) → required ability */
    private array $scopes = [];

    public function register(string $pattern, string $ability): void
    {
        $this->scopes[$pattern] = $ability;
    }

    public function allows(PersonalAccessToken $token, Request $request): bool
    {
        foreach ($this->scopes as $pattern => $ability) {
            if ($request->is($pattern)) {
                return $token->can($ability);
            }
        }

        return $token->can(self::FULL);
    }
}
