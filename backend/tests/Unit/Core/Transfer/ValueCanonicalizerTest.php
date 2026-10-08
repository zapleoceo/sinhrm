<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Transfer;

use App\Modules\Core\Transfer\ValueCanonicalizer;
use PHPUnit\Framework\TestCase;

final class ValueCanonicalizerTest extends TestCase
{
    public function test_postgresql_and_mysql_forms_of_the_same_value_are_equal(): void
    {
        $this->assertSame(ValueCanonicalizer::canonical(true, 'bool'), ValueCanonicalizer::canonical(1, 'bool'));
        $this->assertSame(ValueCanonicalizer::canonical(false, 'bool'), ValueCanonicalizer::canonical('0', 'bool'));
        $this->assertSame(
            ValueCanonicalizer::canonical('{"b": 1, "a": [1, 2], "c": {"y": 1, "x": 2}}', 'json'),
            ValueCanonicalizer::canonical('{"a":[1,2],"b":1,"c":{"x":2,"y":1}}', 'json'),
        );
        $this->assertSame(
            ValueCanonicalizer::canonical('2026-01-02 05:04:05+02', 'datetime'),
            ValueCanonicalizer::canonical('2026-01-02 03:04:05', 'datetime'),
        );
        $this->assertSame(ValueCanonicalizer::canonical('2026-01-02 03:04:05.12', 'datetime'), ValueCanonicalizer::canonical('2026-01-02 03:04:05.120000', 'datetime'));
        $this->assertSame(ValueCanonicalizer::canonical('1500.50', 'decimal'), ValueCanonicalizer::canonical('1500.5000', 'decimal'));
    }

    public function test_real_changes_stay_different(): void
    {
        $this->assertNotSame(ValueCanonicalizer::canonical('{}', 'json'), ValueCanonicalizer::canonical('[]', 'json'));
        $this->assertNotSame(ValueCanonicalizer::canonical('{"0":"x"}', 'json'), ValueCanonicalizer::canonical('["x"]', 'json'));
        $this->assertNotSame(ValueCanonicalizer::canonical(null, 'text'), ValueCanonicalizer::canonical('', 'text'));
        $this->assertNotSame(ValueCanonicalizer::canonical('Anna', 'text'), ValueCanonicalizer::canonical('anna', 'text'));
        $this->assertNotSame(ValueCanonicalizer::canonical('1500.5', 'decimal'), ValueCanonicalizer::canonical('1500.05', 'decimal'));
    }

    public function test_values_are_converted_for_mysql(): void
    {
        $this->assertSame(1, ValueCanonicalizer::forTarget(true, 'boolean'));
        $this->assertSame(0, ValueCanonicalizer::forTarget(false, 'boolean'));
        $this->assertSame('2026-01-02 03:04:05.000000', ValueCanonicalizer::forTarget('2026-01-02 05:04:05+02', 'timestamp with time zone'));
        $this->assertSame('{"a":1}', ValueCanonicalizer::forTarget('{"a":1}', 'jsonb'));
        $this->assertNull(ValueCanonicalizer::forTarget(null, 'boolean'));
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($stream);
        fwrite($stream, "\x00\x01");
        rewind($stream);
        $this->assertSame("\x00\x01", ValueCanonicalizer::forTarget($stream, 'bytea'));
    }

    public function test_column_kind_follows_the_target_type(): void
    {
        $column = ['name' => 'x', 'source' => 'boolean', 'type' => 'tinyint', 'column_type' => 'tinyint(1)', 'collation' => null, 'max_chars' => null, 'max_bytes' => null, 'auto' => false];
        $this->assertSame('bool', ValueCanonicalizer::kind($column));
        $this->assertSame('json', ValueCanonicalizer::kind(['source' => 'jsonb', 'type' => 'json', 'column_type' => 'json'] + $column));
        $this->assertSame('datetime', ValueCanonicalizer::kind(['source' => 'timestamp without time zone', 'type' => 'timestamp', 'column_type' => 'timestamp'] + $column));
        $this->assertSame('text', ValueCanonicalizer::kind(['source' => 'text', 'type' => 'longtext', 'column_type' => 'longtext'] + $column));
    }
}
