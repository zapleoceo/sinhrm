<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Modules\Integrations\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** integrations.settings: MySQL JSON has no literal default (ADR 0010) — the model supplies the empty object. */
final class IntegrationSettingsMysqlSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_has_no_database_default_and_the_model_fills_an_empty_object(): void
    {
        $column = DB::selectOne(
            "select data_type as t, column_default as d, is_nullable as n from information_schema.columns
             where table_schema = database() and table_name = 'integrations' and column_name = 'settings'",
        );
        $this->assertSame('json', $column->t);
        $this->assertNull($column->d);
        $this->assertSame('NO', $column->n);

        $integration = Integration::query()->create(['key' => 'mysql_settings_probe']);

        $this->assertSame([], $integration->refresh()->settings);
        $this->assertSame('{}', DB::table('integrations')->where('key', 'mysql_settings_probe')->value('settings'));
    }
}
