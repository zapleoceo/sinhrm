<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Modules\Core\Support\Export\Csv;
use PHPUnit\Framework\TestCase;

final class CsvTest extends TestCase
{
    public function test_formula_prefixes_are_neutralized_numbers_are_not(): void
    {
        $this->assertSame("'=1+2", Csv::cell('=1+2'));
        $this->assertSame("'+cmd", Csv::cell('+cmd'));
        $this->assertSame("'-2+3", Csv::cell('-2+3'));
        $this->assertSame("'@SUM(A1)", Csv::cell('@SUM(A1)'));
        $this->assertSame("'\tx", Csv::cell("\tx"));
        $this->assertSame("'\rx", Csv::cell("\rx"));
        $this->assertSame('safe = text', Csv::cell('safe = text'));
        $this->assertSame('-5', Csv::cell(-5));
        $this->assertSame('1.5', Csv::cell(1.5));
        $this->assertSame('', Csv::cell(null));
        $this->assertSame('', Csv::cell(''));
    }

    public function test_write_quotes_and_escapes_every_cell(): void
    {
        $out = fopen('php://memory', 'w+b');
        $this->assertNotFalse($out);
        Csv::write($out, ['name', 'n'], [['name' => '=cmd|"/c calc"', 'n' => 3], ['name' => "multi\nline", 'n' => null]]);
        rewind($out);
        $csv = (string) stream_get_contents($out);
        $this->assertSame("\xEF\xBB\xBFname,n\n\"'=cmd|\"\"/c calc\"\"\",3\n\"multi\nline\",\n", $csv);
    }

    public function test_write_appends_the_total_row_with_the_same_guard(): void
    {
        $out = fopen('php://memory', 'w+b');
        $this->assertNotFalse($out);
        Csv::write($out, ['name', 'n', 'pct'], [['name' => '=a', 'n' => 3, 'pct' => 10.0], ['name' => 'b', 'n' => 4, 'pct' => 20.0]],
            ['name' => null, 'n' => 7, 'pct' => null]);
        rewind($out);
        $lines = explode("\n", trim((string) stream_get_contents($out)));

        $this->assertSame("'=a,3,10", $lines[1]);
        $this->assertSame('Total,7,—', $lines[3]);
    }

    public function test_total_label_stays_when_the_first_column_is_summable(): void
    {
        $out = fopen('php://memory', 'w+b');
        $this->assertNotFalse($out);
        Csv::write($out, ['days', 'name'], [['days' => 2, 'name' => 'a'], ['days' => 3, 'name' => 'b']], ['days' => 5, 'name' => null]);
        rewind($out);
        $lines = explode("\n", trim((string) stream_get_contents($out)));

        $this->assertSame('"Total: 5",—', $lines[3]); // fputcsv quotes a cell with a space
    }
}
