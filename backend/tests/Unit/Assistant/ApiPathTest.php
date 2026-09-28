<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Support\ApiPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiPathTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function paths(): iterable
    {
        yield 'plain' => ['candidates/12', 'candidates/12'];
        yield 'leading /api/' => ['/api/timeoff/balances', 'timeoff/balances'];
        yield 'query stripped' => ['candidates?search=Olena', 'candidates'];
        yield 'trailing slash' => ['vacancies/', 'vacancies'];
        yield 'ops' => ['ops/migrate', null];
        yield 'ops exact' => ['OPS', null];
        yield 'mcp recursion' => ['mcp', null];
        yield 'assistant recursion' => ['assistant/turn', null];
        yield 'logout' => ['auth/logout', null];
        yield 'extension token' => ['me/extension-token', null];
        yield 'webhooks' => ['webhooks/telegram', null];
        yield 'public api' => ['public/jobs', null];
        yield 'prefix only as a segment' => ['opsx/list', 'opsx/list'];
        yield 'traversal' => ['candidates/../ops/migrate', null];
        yield 'absolute url' => ['https://evil.example/x', null];
        yield 'protocol relative' => ['//evil.example', null];
        yield 'empty' => ['', null];
        yield 'encoded traversal' => ['candidates/%2e%2e/ops/migrate', null];
        yield 'encoded slash' => ['ops%2fmigrate', null];
        yield 'encoded query is fine' => ['candidates?search=%D0%9E', 'candidates'];
        yield 'backslash' => ['ops\\migrate', null];
    }

    #[DataProvider('paths')]
    public function test_normalize(string $path, ?string $expected): void
    {
        $this->assertSame($expected, ApiPath::normalize($path));
    }

    public function test_inline_query_is_merged_under_explicit_parameters(): void
    {
        $this->assertSame(['search' => 'b', 'page' => '2'], ApiPath::query('candidates?search=a&page=2', ['search' => 'b']));
        $this->assertSame([], ApiPath::query('candidates', []));
    }
}
