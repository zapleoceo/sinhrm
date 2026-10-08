<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Support\Database\Sql;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sql helper on the real database: the same assertions run in the PostgreSQL job (tests) and MySQL job (tests-mysql). */
final class PortableSqlTest extends TestCase
{
    use RefreshDatabase;

    public function test_nulls_go_last_or_first_in_both_directions(): void
    {
        $never = User::factory()->create(['last_login_at' => null]);
        $old = User::factory()->create(['last_login_at' => Carbon::parse('2026-01-01 10:00:00')]);
        $new = User::factory()->create(['last_login_at' => Carbon::parse('2026-05-01 10:00:00')]);

        $ids = static function (string $dir, bool $last): array {
            $q = User::query()->select('id');
            $last ? Sql::orderByNullsLast($q, 'last_login_at', $dir) : Sql::orderByNullsFirst($q, 'last_login_at', $dir);

            return $q->orderBy('id')->pluck('id')->all();
        };

        $this->assertSame([$old->id, $new->id, $never->id], $ids('asc', true));
        $this->assertSame([$new->id, $old->id, $never->id], $ids('desc', true));
        $this->assertSame([$never->id, $old->id, $new->id], $ids('asc', false));
        $this->assertSame([$never->id, $new->id, $old->id], $ids('DESC', false));
    }

    public function test_contains_is_case_insensitive_for_cyrillic_and_keeps_wildcards_literal(): void
    {
        $ivan = User::factory()->create(['name' => 'Іван Петренко']);
        $percent = User::factory()->create(['name' => 'Знижка 50% Олена']);
        User::factory()->create(['name' => 'Знижка 500 Марко']);

        $find = static function (string $needle): array {
            $q = User::query()->select('id');
            Sql::whereContainsCi($q, 'name', $needle);

            return $q->orderBy('id')->pluck('id')->all();
        };

        $this->assertSame([$ivan->id], $find('іВАН'));
        $this->assertSame([$percent->id], $find('50%'));
        $this->assertSame([], $find('_нижка 5_0'));
    }

    public function test_explicit_expression_orders_by_a_subquery_and_contains_casts_to_text(): void
    {
        $a = User::factory()->create(['name' => 'Anna', 'last_login_at' => null]);
        $b = User::factory()->create(['name' => 'Boris', 'last_login_at' => Carbon::parse('2026-01-01 10:00:00')]);

        $q = User::query()->select('id');
        Sql::orderByNullsLast($q, new Expression('(select u2.last_login_at from users u2 where u2.id = users.id)'), 'desc');
        $this->assertSame([$b->id, $a->id], $q->orderBy('id')->pluck('id')->all());

        $byId = User::query()->select('id');
        Sql::whereContainsCi($byId, 'users.id', (string) $b->id, asText: true);
        $this->assertContains($b->id, $byId->pluck('id')->all());
    }

    /**
     * Known divergence (ADR 0010): MySQL utf8mb4_0900_ai_ci ignores diacritics (й = и, é = e), PostgreSQL ignores only case.
     * The test pins it so a change of collation or of the helper does not slip by unnoticed.
     */
    public function test_contains_diacritics_known_divergence_mysql_is_wider(): void
    {
        $user = User::factory()->create(['name' => 'Йосип Résumé']);
        $find = static function (string $needle): array {
            $q = User::query()->select('id');
            Sql::whereContainsCi($q, 'name', $needle);

            return $q->pluck('id')->all();
        };
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

        $this->assertSame([$user->id], $find('йосип'), 'case-insensitivity is the same on every driver');
        $this->assertSame($mysql ? [$user->id] : [], $find('иосип'), 'й = и only on MySQL');
        $this->assertSame($mysql ? [$user->id] : [], $find('resume'), 'é = e only on MySQL');
    }

    public function test_json_text_reads_a_key_on_the_current_driver(): void
    {
        DB::table('integrations')->insert(['key' => 'portable_sql_probe', 'status' => 'off', 'settings' => json_encode(['mode' => 'Тест']), 'created_at' => now(), 'updated_at' => now()]);

        $value = DB::table('integrations')->where('key', 'portable_sql_probe')
            ->selectRaw(Sql::jsonText(DB::getDriverName(), 'settings', 'mode').' as mode')->value('mode');

        $this->assertSame('Тест', $value);
    }

    public function test_mysql_session_is_utf8mb4_strict_and_utc(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL session settings (ADR 0010) — checked in the tests-mysql job.');
        }
        $row = DB::selectOne('select @@session.time_zone as tz, @@session.sql_mode as mode, @@session.collation_connection as coll, @@session.character_set_connection as cs');

        $this->assertSame('+00:00', $row->tz);
        $this->assertSame('utf8mb4', $row->cs);
        $this->assertSame('utf8mb4_0900_ai_ci', $row->coll);
        foreach (['ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES', 'NO_ZERO_DATE', 'ERROR_FOR_DIVISION_BY_ZERO'] as $mode) {
            $this->assertStringContainsString($mode, $row->mode);
        }
    }
}
