<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Modules\Reports\Support\Csv;
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
}
