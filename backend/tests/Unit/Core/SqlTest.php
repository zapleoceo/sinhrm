<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Modules\Core\Support\Database\Sql;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Driver-specific JSON text expression and input guards of the portable SQL helper (ADR 0010). */
final class SqlTest extends TestCase
{
    public function test_json_text_per_driver(): void
    {
        $this->assertSame("meta->>'thread'", Sql::jsonText('pgsql', 'meta', 'thread'));
        $this->assertSame("json_unquote(json_extract(t.meta, '$.\"thread\"'))", Sql::jsonText('mysql', 't.meta', 'thread'));
        $this->assertSame("json_extract(meta, '$.\"thread\"')", Sql::jsonText('sqlite', 'meta', 'thread'));
    }

    public function test_json_text_rejects_unsafe_identifiers(): void
    {
        foreach ([['meta;drop', 'k'], ['meta', "k'"], ['meta', 'a.b']] as [$column, $key]) {
            try {
                Sql::jsonText('pgsql', $column, $key);
                $this->fail("accepted {$column}/{$key}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cast_text_uses_a_string_type_each_driver_accepts(): void
    {
        $this->assertSame('cast(x.cost as varchar(255))', Sql::castText('pgsql', 'x.cost'));
        $this->assertSame('cast(x.cost as char(64))', Sql::castText('mysql', 'x.cost', 64));
        $this->assertSame('cast(x.cost as text)', Sql::castText('sqlite', 'x.cost'));
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

    public function test_unknown_driver_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Sql::jsonText('sqlsrv', 'meta', 'k');
    }
}
