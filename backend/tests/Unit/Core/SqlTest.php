<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Modules\Core\Support\Database\Sql;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** SQL fragments of the MySQL-only helper (ADR 0010) and its input guards. */
final class SqlTest extends TestCase
{
    public function test_json_text_is_the_expression_laravel_compiles_for_column_arrow_key(): void
    {
        $this->assertSame("json_unquote(json_extract(t.meta, '$.\"thread\"'))", Sql::jsonText('mysql', 't.meta', 'thread'));
        $this->assertSame("json_unquote(json_extract(meta, '$.\"thread\"'))", Sql::jsonText('mariadb', 'meta', 'thread'));
    }

    public function test_json_text_rejects_unsafe_identifiers(): void
    {
        foreach ([['meta;drop', 'k'], ['meta', "k'"], ['meta', 'a.b']] as [$column, $key]) {
            try {
                Sql::jsonText('mysql', $column, $key);
                $this->fail("accepted {$column}/{$key}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cast_text_uses_char_because_mysql_has_no_varchar_cast(): void
    {
        $this->assertSame('cast(x.cost as char(255))', Sql::castText('mysql', 'x.cost'));
        $this->assertSame('cast(x.cost as char(64))', Sql::castText('mysql', 'x.cost', 64));
    }

    public function test_expression_guard_rejects_anything_but_a_column_identifier(): void
    {
        $query = $this->createStub(Builder::class);

        foreach (['name desc', "name'", 'name;drop table users', 'name--', '(select 1)', 'a.b.c', 'lower(name)', ''] as $bad) {
            foreach ([
                static fn () => Sql::orderByNullsLast($query, $bad, 'asc'),
                static fn () => Sql::orderByNullsFirst($query, $bad, 'desc'),
                static fn () => Sql::whereContainsCi($query, $bad, 'x'),
            ] as $call) {
                try {
                    $call();
                    $this->fail("accepted «{$bad}»");
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    /** Only MySQL is supported (ADR 0010): asking for another dialect is a programming error. */
    public function test_drivers_other_than_mysql_are_rejected(): void
    {
        foreach (['sqlite', 'sqlsrv', 'unknown'] as $driver) {
            foreach ([
                static fn () => Sql::jsonText($driver, 'meta', 'k'),
                static fn () => Sql::castText($driver, 'x.cost'),
            ] as $call) {
                try {
                    $call();
                    $this->fail("accepted driver {$driver}");
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }
}
