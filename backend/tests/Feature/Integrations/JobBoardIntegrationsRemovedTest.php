<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\User;
use App\Modules\Auth\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Work.ua / Robota.ua / Djinni have no employer API: no catalog cards, and the cleanup migration drops their rows. */
final class JobBoardIntegrationsRemovedTest extends TestCase
{
    use RefreshDatabase;

    private const array KEYS = ['work_ua', 'robota_ua', 'djinni'];

    public function test_catalog_does_not_list_job_boards(): void
    {
        $user = User::factory()->withRole(UserRole::Superadmin)->create();
        /** @var list<array{key: string}> $data */
        $data = $this->actingAs($user)->getJson('/api/integrations')->assertOk()->json('data');
        $keys = array_column($data, 'key');

        foreach (self::KEYS as $key) {
            $this->assertNotContains($key, $keys);
            $this->actingAs($user)->getJson("/api/integrations/{$key}/logs")->assertNotFound();
        }
        $this->assertContains('meta_lead_ads', $keys);
    }

    public function test_migration_deletes_job_board_rows_secrets_and_logs_only(): void
    {
        $now = now();
        $ids = [];
        foreach ([...self::KEYS, 'keep_me_test'] as $key) {
            $ids[$key] = DB::table('integrations')->insertGetId(['key' => $key, 'status' => 'off', 'settings' => '{}', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('integration_secrets')->insert(['integration_id' => $ids[$key], 'name' => 'api_token', 'value' => 'x', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('integration_logs')->insert(['integration_id' => $ids[$key], 'level' => 'info', 'message' => 'm']);
        }

        $migration = require base_path('database/migrations/2026_10_09_100001_delete_job_board_integrations.php');
        $migration->up();
        $migration->up(); // idempotent

        $gone = array_values(array_intersect_key($ids, array_flip(self::KEYS)));
        $this->assertSame(0, DB::table('integrations')->whereIn('key', self::KEYS)->count());
        $this->assertSame(0, DB::table('integration_secrets')->whereIn('integration_id', $gone)->count());
        $this->assertSame(0, DB::table('integration_logs')->whereIn('integration_id', $gone)->count());
        $keep = $ids['keep_me_test'];
        $this->assertTrue(DB::table('integrations')->where('id', $keep)->exists());
        $this->assertSame(1, DB::table('integration_secrets')->where('integration_id', $keep)->count());
        $this->assertSame(1, DB::table('integration_logs')->where('integration_id', $keep)->count());

        $migration->down(); // no-op: deletion is not reversible
        $this->assertSame(0, DB::table('integrations')->whereIn('key', self::KEYS)->count());
    }
}
