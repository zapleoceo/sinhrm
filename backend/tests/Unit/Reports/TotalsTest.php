<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Modules\Reports\Support\Totals;
use PHPUnit\Framework\TestCase;

final class TotalsTest extends TestCase
{
    /** @var list<array{key: string, type: string, total?: string, of?: list<string>, weight?: string}> */
    private const array COLUMNS = [
        ['key' => 'source', 'type' => 'string'],
        ['key' => 'candidates', 'type' => 'number', 'total' => 'sum'],
        ['key' => 'hired', 'type' => 'number', 'total' => 'sum'],
        ['key' => 'hire_rate_pct', 'type' => 'percent', 'total' => 'ratio', 'of' => ['hired', 'candidates']],
        ['key' => 'avg_days', 'type' => 'number', 'total' => 'avg-weighted', 'weight' => 'hired'],
        ['key' => 'median_days', 'type' => 'number', 'total' => 'none'],
        ['key' => 'share_pct', 'type' => 'percent'],
    ];

    public function test_sums_additive_columns_and_computes_true_ratios(): void
    {
        $total = Totals::row(self::COLUMNS, [
            ['source' => 'a', 'candidates' => 10, 'hired' => 1, 'hire_rate_pct' => 10.0, 'avg_days' => 20, 'median_days' => 20, 'share_pct' => 25.0],
            ['source' => 'b', 'candidates' => 30, 'hired' => 3, 'hire_rate_pct' => 10.0, 'avg_days' => 40, 'median_days' => 35, 'share_pct' => 75.0],
            ['source' => 'c', 'candidates' => 10, 'hired' => 0, 'hire_rate_pct' => 0.0, 'avg_days' => 0, 'median_days' => null, 'share_pct' => 0.0],
        ]);

        $this->assertSame([
            'source' => null,
            'candidates' => 50,
            'hired' => 4,
            'hire_rate_pct' => 8.0,     // 4 / 50, not the mean of the row percentages
            'avg_days' => 35.0,         // (20·1 + 40·3) / 4
            'median_days' => null,
            'share_pct' => null,        // no hint = no total
        ], $total);
    }

    public function test_no_total_row_for_fewer_than_two_rows(): void
    {
        $this->assertNull(Totals::row(self::COLUMNS, []));
        $this->assertNull(Totals::row(self::COLUMNS, [['source' => 'a', 'candidates' => 1, 'hired' => 1]]));
    }

    public function test_a_suppressed_cell_hides_the_column_total(): void
    {
        $columns = [['key' => 'survey', 'type' => 'string'], ['key' => 'responses', 'type' => 'number', 'total' => 'sum'],
            ['key' => 'rate_pct', 'type' => 'percent', 'total' => 'ratio', 'of' => ['responses', 'invited']], ['key' => 'invited', 'type' => 'number', 'total' => 'sum']];
        $total = Totals::row($columns, [
            ['survey' => 'Q1', 'responses' => 12, 'rate_pct' => 60.0, 'invited' => 20],
            ['survey' => 'Q2', 'responses' => null, 'rate_pct' => null, 'invited' => 4],   // group below the minimum
        ]);

        $this->assertNotNull($total);
        $this->assertNull($total['responses']);
        $this->assertNull($total['rate_pct']);
        $this->assertSame(24, $total['invited']);
    }

    public function test_ratio_with_zero_denominator_and_float_sums(): void
    {
        $columns = [['key' => 'cost', 'type' => 'number', 'total' => 'sum'], ['key' => 'hired', 'type' => 'number', 'total' => 'sum'],
            ['key' => 'cost_per_hire', 'type' => 'number', 'total' => 'ratio', 'of' => ['cost', 'hired']]];
        $total = Totals::row($columns, [['cost' => 100.1, 'hired' => 0], ['cost' => 0.2, 'hired' => 0]]);

        $this->assertSame(['cost' => 100.3, 'hired' => 0, 'cost_per_hire' => null], $total);
    }
}
