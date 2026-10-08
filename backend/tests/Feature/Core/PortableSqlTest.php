<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Support\Database\Sql;
use Illuminate\Database\Query\Expression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sql helper on the real MySQL 8.4 database (CI job `tests`, ADR 0011): NULLS LAST/FIRST, contains, JSON, session. */
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
     * utf8mb4_0900_ai_ci (ADR 0011): the search ignores case and Latin diacritics (é = e), Cyrillic й is not folded into и.
     * Under PostgreSQL only case was ignored — accepted widening, pinned so a collation change does not slip by.
     */
    public function test_contains_ignores_latin_diacritics_but_keeps_cyrillic_short_i(): void
    {
        $user = User::factory()->create(['name' => 'Йосип Résumé']);
        $find = static function (string $needle): array {
            $q = User::query()->select('id');
            Sql::whereContainsCi($q, 'name', $needle);

            return $q->pluck('id')->all();
        };

        $this->assertSame([$user->id], $find('йосип'), 'case-insensitive');
        $this->assertSame([], $find('иосип'), 'й is not folded into и');
        $this->assertSame([$user->id], $find('resume'), 'é = e under utf8mb4_0900_ai_ci');
    }

    public function test_json_text_reads_a_key_on_the_current_driver(): void
    {
        DB::table('integrations')->insert(['key' => 'portable_sql_probe', 'status' => 'off', 'settings' => json_encode(['mode' => 'Тест']), 'created_at' => now(), 'updated_at' => now()]);

        $value = DB::table('integrations')->where('key', 'portable_sql_probe')
            ->selectRaw(Sql::jsonText('mysql', 'settings', 'mode').' as mode')->value('mode');

        $this->assertSame('Тест', $value);
    }

    public function test_mysql_session_is_utf8mb4_strict_and_utc(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertStringStartsWith('8.4.', (string) DB::selectOne('select version() as v')->v);
        $row = DB::selectOne('select @@session.time_zone as tz, @@session.sql_mode as mode, @@session.collation_connection as coll, @@session.character_set_connection as cs');

        $this->assertSame('+00:00', $row->tz);
        $this->assertSame('utf8mb4', $row->cs);
        $this->assertSame('utf8mb4_0900_ai_ci', $row->coll);
        foreach (['ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES', 'NO_ZERO_DATE', 'ERROR_FOR_DIVISION_BY_ZERO'] as $mode) {
            $this->assertStringContainsString($mode, $row->mode);
        }
    }
}
