<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Modules\Ai\Exceptions\InvalidAiOutput;
use App\Modules\Ai\Support\JsonOutput;
use App\Modules\Ai\Support\PiiRedactor;
use App\Modules\MailAgent\Support\MailBodyCleaner;
use PHPUnit\Framework\TestCase;

final class AiSupportTest extends TestCase
{
    public function test_json_is_extracted_from_fences_and_stray_text(): void
    {
        $this->assertSame(['a' => 1], JsonOutput::decode('{"a":1}'));
        $this->assertSame(['a' => 1], JsonOutput::decode("```json\n{\"a\":1}\n```"));
        $this->assertSame(['a' => 1], JsonOutput::decode('Here you go: {"a":1} — done'));
        $this->assertNull(JsonOutput::decode('[1,2]'));
        $this->assertNull(JsonOutput::decode('not json'));
        $this->assertNull(JsonOutput::decode(''));
        $this->assertNull(JsonOutput::decode(null));
    }

    public function test_typed_readers_clamp_and_reject(): void
    {
        $this->assertSame(100, JsonOutput::int(['s' => 140], 's', 0, 100));
        $this->assertSame(7, JsonOutput::int(['s' => 7.0], 's', 0, 100));
        $this->assertSame(['a', 'b'], JsonOutput::strings(['l' => [' a ', '', 'b', 'c']], 'l', 2, 10));
        $this->assertNull(JsonOutput::text(['t' => '  '], 't', 5));
        $this->assertSame('abc', JsonOutput::text(['t' => 'abcdef'], 't', 3));
        $this->expectException(InvalidAiOutput::class);
        JsonOutput::oneOf(['k' => 'other'], 'k', ['a', 'b']);
    }

    public function test_redactor_removes_contacts_links_and_names(): void
    {
        $text = 'Олена Тестова, тел. +38 (067) 555-12-34, olena@example.test, t.me @olena_test, https://example.test/cv/1. Досвід 5 років.';

        $out = PiiRedactor::redact($text, ['Олена Тестова']);

        foreach (['Олена', 'Тестова', '555-12-34', 'olena@example.test', '@olena_test', 'https://'] as $pii) {
            $this->assertStringNotContainsString($pii, $out);
        }
        $this->assertStringContainsString('Досвід 5 років', $out);
    }

    public function test_mail_body_cleaner_drops_quotes_and_signatures_and_limits_length(): void
    {
        $body = "Добрий день!\nХочу на вакансію.\n> quoted line\n-- \nПідпис Іван\nтел. 000";
        $this->assertSame('Добрий день! Хочу на вакансію.', MailBodyCleaner::clean($body));
        $this->assertSame('Текст', MailBodyCleaner::clean("Текст\nOn Mon, 1 Jan 2026 Someone wrote:\n> old"));
        $this->assertSame(MailBodyCleaner::LIMIT, mb_strlen(MailBodyCleaner::clean(str_repeat('я', 5000))));
    }
}
