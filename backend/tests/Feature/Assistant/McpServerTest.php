<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** MCP token lifecycle and POST /api/mcp (laravel/mcp): scoping, tool list, calls through the real API as the user. */
final class McpServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_is_issued_once_shown_and_revoked_from_the_session(): void
    {
        $user = User::factory()->withRole(UserRole::Employee)->create();

        $this->actingAs($user)->getJson('/api/assistant/mcp-token')->assertOk()->assertJsonPath('data.active', false);
        $token = $this->actingAs($user)->postJson('/api/assistant/mcp-token')->assertCreated()
            ->assertJsonPath('data.active', true)->json('data.token');
        $this->assertIsString($token);
        $this->actingAs($user)->getJson('/api/assistant/mcp-token')->assertOk()
            ->assertJsonPath('data.active', true)->assertJsonMissingPath('data.token');

        $this->actingAs($user)->deleteJson('/api/assistant/mcp-token')->assertNoContent();
        $this->actingAs($user)->getJson('/api/assistant/mcp-token')->assertJsonPath('data.active', false);
    }

    public function test_the_mcp_token_opens_only_the_mcp_endpoint(): void
    {
        $token = $this->tokenFor(User::factory()->withRole(UserRole::Superadmin)->create());
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/assistant/status')->assertUnauthorized();
        $this->withToken($token)->postJson('/api/assistant/mcp-token')->assertUnauthorized();
        $this->rpc($token, 'ping')->assertOk();
    }

    public function test_session_users_and_other_tokens_cannot_use_the_mcp_endpoint(): void
    {
        $user = User::factory()->withRole(UserRole::Employee)->create();
        $clipper = $user->createToken('extension', ['clipper'])->plainTextToken;

        $this->rpc($clipper, 'ping')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
    }

    public function test_tools_list_offers_server_side_tools_with_hints(): void
    {
        $token = $this->tokenFor(User::factory()->withRole(UserRole::Employee)->create());

        $this->rpc($token, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']])
            ->assertOk()->assertJsonPath('result.serverInfo.name', 'SinHRM');
        $tools = collect($this->rpc($token, 'tools/list')->assertOk()->json('result.tools'))->keyBy('name');

        $this->assertEqualsCanonicalizing(['api_get', 'api_write', 'find_endpoints'], $tools->keys()->all());
        $this->assertTrue($tools['api_get']['annotations']['readOnlyHint']);
        $this->assertTrue($tools['api_write']['annotations']['destructiveHint']);
        $this->assertSame(['method', 'path', 'summary'], $tools['api_write']['inputSchema']['required']);
    }

    public function test_calls_go_through_the_real_api_with_the_users_rights(): void
    {
        $employee = User::factory()->withRole(UserRole::Employee)->create(['name' => 'Synthetic Employee']);
        $token = $this->tokenFor($employee);

        $me = $this->call_tool($token, 'api_get', ['path' => 'auth/me']);
        $this->assertFalse($me['isError'] ?? false);
        $this->assertStringContainsString('Synthetic Employee', (string) json_encode($me, JSON_UNESCAPED_UNICODE));

        // Admin-only endpoint: the policy of the real route answers, not the helper.
        $users = $this->call_tool($token, 'api_get', ['path' => 'users']);
        $this->assertTrue($users['isError'] ?? false);
        $this->assertStringContainsString('403', (string) json_encode($users));

        $ops = $this->call_tool($token, 'api_write', ['method' => 'POST', 'path' => 'ops/migrate', 'summary' => 'x']);
        $this->assertStringContainsString('forbidden_path', (string) json_encode($ops));

        $found = $this->call_tool($token, 'find_endpoints', ['query' => 'users']);
        $this->assertStringNotContainsString('"path":"users"', (string) json_encode($found), 'Modules the user cannot open are not listed.');
    }

    private function tokenFor(User $user): string
    {
        $token = $this->actingAs($user)->postJson('/api/assistant/mcp-token')->assertCreated()->json('data.token');
        $this->assertIsString($token);
        $this->app['auth']->forgetGuards();

        return $token;
    }

    /** @param  array<string, mixed>  $params */
    private function rpc(string $token, string $method, array $params = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)
            ->withHeaders(['Accept' => 'application/json, text/event-stream'])
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function call_tool(string $token, string $name, array $arguments): array
    {
        $result = $this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => $arguments])->assertOk()->json('result');
        $this->assertIsArray($result);

        return $result;
    }
}
