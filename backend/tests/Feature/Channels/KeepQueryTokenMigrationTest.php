<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ChannelFixtures;
use Tests\TestCase;

/** HRM-26: existing telephony connections keep ?token= (flag on, deprecated); new ones start with the flag off. */
final class KeepQueryTokenMigrationTest extends TestCase
{
    use ChannelFixtures, RefreshDatabase;

    private const string MIGRATION = 'app/Modules/Channels/Database/Migrations/2026_10_09_100001_keep_query_token_for_existing_telephony.php';

    public function test_up_turns_the_flag_on_only_for_telephony_with_a_token_and_keeps_explicit_values(): void
    {
        $this->channel('phonet', IntegrationStatus::Connected, ['webhook_token' => self::PHONE_TOKEN], ['domain' => 'demo.example.test']);
        $this->channel('ringostat', IntegrationStatus::Demo, ['webhook_token' => self::PHONE_TOKEN], ['project_id' => '1', 'webhook_query_token' => 'off']);
        $this->channel('binotel', IntegrationStatus::Demo, ['api_key' => 'fake-key-0009']);
        $this->viber();

        $migration = require base_path(self::MIGRATION);
        $migration->up();
        $migration->up(); // re-runnable

        $this->assertSame(['domain' => 'demo.example.test', 'webhook_query_token' => 'on'], $this->settings('phonet'));
        $this->assertSame('off', $this->settings('ringostat')['webhook_query_token'] ?? null);
        $this->assertArrayNotHasKey('webhook_query_token', $this->settings('binotel'));
        $this->assertArrayNotHasKey('webhook_query_token', $this->settings('viber'));

        $migration->down();
        $this->assertSame(['domain' => 'demo.example.test'], $this->settings('phonet'));
    }

    /** @return array<string, mixed> */
    private function settings(string $key): array
    {
        return Integration::query()->where('key', $key)->sole()->settings;
    }
}
