<?php

declare(strict_types=1);

namespace Tests\Unit\SafeSpeak;

use App\Modules\SafeSpeak\Http\Requests\SubmitReportRequest;
use PHPUnit\Framework\TestCase;

final class SubmitReportRequestTest extends TestCase
{
    public function test_subject_and_body_rules_and_accessors_are_unchanged(): void
    {
        $request = new SubmitReportRequest;
        $request->merge(['subject' => ' Pressure ', 'body' => ' details ']);
        $rules = $request->rules();

        self::assertSame(['category', 'subject', 'body'], array_keys($rules));
        self::assertSame(['required', 'string', 'max:200'], $rules['subject']);
        self::assertSame(['required', 'string', 'max:10000'], $rules['body']);
        self::assertSame('Pressure', $request->subject());
        self::assertSame(' details ', $request->body());
    }
}
