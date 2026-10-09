<?php

declare(strict_types=1);

namespace Tests\Unit\Desk;

use App\Modules\Desk\Http\Requests\OpenCaseRequest;
use PHPUnit\Framework\TestCase;

final class OpenCaseRequestTest extends TestCase
{
    public function test_rules_and_accessors_are_unchanged(): void
    {
        $request = new OpenCaseRequest;
        $request->merge(['category_id' => '7', 'subject' => ' Laptop ', 'body' => ' broken ']);

        self::assertSame([
            'category_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
        ], $request->rules());
        self::assertSame(7, $request->categoryId());
        self::assertSame('Laptop', $request->subject());
        self::assertSame(' broken ', $request->body());
    }
}
