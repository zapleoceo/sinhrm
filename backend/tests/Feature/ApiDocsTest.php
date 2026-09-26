<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** /api/docs (Scramble UI) and /api/docs.json: superadmin only; the spec builds and covers every module (docs/guides/api.md). */
final class ApiDocsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Static analysis of every controller/request is heavier than a normal request.
        ini_set('memory_limit', '1G');
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/docs.json')->assertUnauthorized();
        $this->getJson('/api/docs')->assertUnauthorized();
    }

    public function test_non_superadmin_gets_403(): void
    {
        $admin = User::factory()->withRole(UserRole::Admin)->create();

        $this->actingAs($admin)->getJson('/api/docs.json')->assertForbidden();
        $this->actingAs($admin)->get('/api/docs')->assertForbidden();
    }

    public function test_superadmin_gets_the_spec_grouped_by_module(): void
    {
        $super = User::factory()->withRole(UserRole::Superadmin)->create();

        $spec = $this->actingAs($super)->getJson('/api/docs.json')->assertOk()->json();

        $this->assertIsArray($spec);
        $this->assertStringStartsWith('3.', $spec['openapi']);
        $this->assertArrayNotHasKey('/docs', $spec['paths'], 'docs routes must not document themselves');

        $tags = [];
        $operations = 0;
        foreach ($spec['paths'] as $path) {
            foreach ($path as $operation) {
                if (is_array($operation) && isset($operation['operationId'])) {
                    $operations++;
                    array_push($tags, ...$operation['tags']);
                }
            }
        }
        $modules = array_map('basename', glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: []);
        // Every module that registers /api routes shows up as its own tag; nothing falls into "Other".
        $this->assertNotContains('Other', $tags);
        $this->assertSame([], array_diff(array_unique($tags), $modules));
        $this->assertGreaterThan(200, $operations);
    }

    public function test_superadmin_gets_the_ui(): void
    {
        $super = User::factory()->withRole(UserRole::Superadmin)->create();

        $this->actingAs($super)->get('/api/docs')->assertOk()->assertSee('SinHRM API');
    }
}
