<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Modules\Core\Http\Requests\Concerns\HasSubjectAndBody;
use Illuminate\Foundation\Http\FormRequest;
use PHPUnit\Framework\TestCase;

final class HasSubjectAndBodyTest extends TestCase
{
    public function test_rules_limit_subject_and_body(): void
    {
        $request = new class extends FormRequest
        {
            use HasSubjectAndBody;

            /** @return array<string, mixed> */
            public function rules(): array
            {
                return $this->subjectAndBodyRules();
            }
        };

        self::assertSame([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
        ], $request->rules());
    }

    public function test_subject_is_trimmed_and_body_kept_as_typed(): void
    {
        $request = new class extends FormRequest
        {
            use HasSubjectAndBody;
        };
        $request->merge(['subject' => "  Hello \n", 'body' => "  line 1\nline 2  "]);

        self::assertSame('Hello', $request->subject());
        self::assertSame("  line 1\nline 2  ", $request->body());
    }
}
