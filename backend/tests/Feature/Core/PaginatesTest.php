<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** ?perPage arrives as text: one rule (1..max) and one cast for every paginated list. */
final class PaginatesTest extends TestCase
{
    /** @param  array<string, mixed>  $query */
    private function request(array $query, int $max = 200, int $default = 50): PaginatedRequestFixture
    {
        $request = new PaginatedRequestFixture;
        $request->max = $max;
        $request->default = $default;
        $request->query->replace($query);

        return $request;
    }

    /** @param  array<string, mixed>  $query */
    private function passes(array $query, int $max = 200): bool
    {
        $request = $this->request($query, $max);

        return Validator::make($request->query->all(), $request->rules())->passes();
    }

    public function test_string_values_are_cast_and_absent_means_the_default(): void
    {
        $this->assertSame(20, $this->request(['perPage' => '20'])->perPage());
        $this->assertSame(1, $this->request(['perPage' => '1'])->perPage());
        $this->assertSame(50, $this->request([])->perPage());
        $this->assertSame(20, $this->request([], 100, 20)->perPage());
    }

    public function test_the_rule_keeps_the_value_within_one_and_the_maximum(): void
    {
        $this->assertTrue($this->passes([]));
        $this->assertTrue($this->passes(['perPage' => '200']));
        $this->assertTrue($this->passes(['perPage' => '100'], 100));
        $this->assertFalse($this->passes(['perPage' => '201']));
        $this->assertFalse($this->passes(['perPage' => '101'], 100));
        $this->assertFalse($this->passes(['perPage' => '0']));
        $this->assertFalse($this->passes(['perPage' => 'abc']));
        $this->assertFalse($this->passes(['perPage' => '2.5']));
    }
}
