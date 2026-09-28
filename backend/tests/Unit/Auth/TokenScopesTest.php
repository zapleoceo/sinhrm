<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Modules\Auth\Support\TokenScopes;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\TestCase;

final class TokenScopesTest extends TestCase
{
    public function test_a_scoped_token_opens_only_its_registered_paths(): void
    {
        $scopes = new TokenScopes;
        $scopes->register('api/clipper/*', 'clipper');
        $scopes->register('api/mcp', 'mcp');
        $mcp = self::token(['mcp']);
        $clipper = self::token(['clipper']);

        $this->assertTrue($scopes->allows($mcp, Request::create('/api/mcp', 'POST')));
        $this->assertFalse($scopes->allows($mcp, Request::create('/api/candidates')));
        $this->assertFalse($scopes->allows($mcp, Request::create('/api/clipper/me')));
        $this->assertTrue($scopes->allows($clipper, Request::create('/api/clipper/me')));
        $this->assertFalse($scopes->allows($clipper, Request::create('/api/mcp', 'POST')));
    }

    public function test_other_paths_need_the_full_ability(): void
    {
        $scopes = new TokenScopes;

        $this->assertTrue($scopes->allows(self::token([TokenScopes::FULL]), Request::create('/api/users')));
        $this->assertFalse($scopes->allows(self::token(['mcp']), Request::create('/api/users')));
    }

    /** @param  list<string>  $abilities */
    private static function token(array $abilities): PersonalAccessToken
    {
        $token = new PersonalAccessToken;
        $token->abilities = $abilities;

        return $token;
    }
}
