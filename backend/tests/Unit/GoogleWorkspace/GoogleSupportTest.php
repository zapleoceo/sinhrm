<?php

declare(strict_types=1);

namespace Tests\Unit\GoogleWorkspace;

use App\Modules\GoogleWorkspace\Enums\GoogleService;
use App\Modules\GoogleWorkspace\Services\GoogleConnectService;
use App\Modules\GoogleWorkspace\Services\SheetsImportService;
use App\Modules\GoogleWorkspace\Support\MimeText;
use App\Modules\GoogleWorkspace\Support\SheetRange;
use PHPUnit\Framework\TestCase;

final class GoogleSupportTest extends TestCase
{
    public function test_mime_prefers_plain_text_and_converts_charset(): void
    {
        $payload = ['mimeType' => 'multipart/alternative', 'parts' => [
            ['mimeType' => 'text/html', 'body' => ['data' => self::b64('<p>HTML</p>')]],
            ['mimeType' => 'multipart/related', 'parts' => [[
                'mimeType' => 'text/plain',
                'headers' => [['name' => 'Content-Type', 'value' => 'text/plain; charset=windows-1251']],
                'body' => ['data' => self::b64((string) mb_convert_encoding("Привіт,\r\n\r\n\r\n\r\nсвіт", 'Windows-1251', 'UTF-8'))],
            ]]],
        ]];

        $this->assertSame("Привіт,\n\nсвіт", MimeText::extract($payload));
    }

    public function test_html_to_text_drops_scripts_and_keeps_links(): void
    {
        $text = MimeText::htmlToText('<div>Hello&nbsp;<b>World</b></div><script>steal()</script><a href="https://x.example.test/cv">CV</a><br>End &amp; more');

        $this->assertSame("Hello World\nCV https://x.example.test/cv\nEnd & more", $text);
    }

    public function test_address_parsing(): void
    {
        $this->assertSame(['olena@example.test', 'Олена Тест'], MimeText::parseAddress('"Олена Тест" <Olena@Example.test>'));
        $this->assertSame(['a@example.test', null], MimeText::parseAddress('a@example.test'));
        $this->assertSame([null, null], MimeText::parseAddress('not an address'));
    }

    public function test_sheet_url_and_ranges(): void
    {
        $this->assertSame('abcdefghijklmnopqrstuvwxyz_-01', SheetRange::spreadsheetId('https://docs.google.com/spreadsheets/d/abcdefghijklmnopqrstuvwxyz_-01/edit#gid=0'));
        $this->assertNull(SheetRange::spreadsheetId('https://docs.google.com.evil.example.test/spreadsheets/d/abcdefghijklmnopqrstuvwxyz'));
        $this->assertNull(SheetRange::spreadsheetId('https://docs.google.com/document/d/abcdefghijklmnopqrstuvwxyz'));
        $this->assertSame('A2:Z501', SheetRange::rows('', 2, 501));
        $this->assertSame("'Лист ''1'''!A1:Z11", SheetRange::rows("Лист '1'", 1, 11));
    }

    public function test_mapping_suggestion_prefers_exact_then_specific_hints(): void
    {
        $this->assertEquals(
            ['full_name' => 0, 'utm_source' => 4, 'utm_campaign' => 5, 'telegram' => 3, 'email' => 2, 'phone' => 1],
            SheetsImportService::suggest(['Ім\'я', 'Номер телефону', 'Ваш e-mail', 'Телеграм', 'utm_source', 'utm_campaign']),
        );
        $this->assertSame(['email' => 1], SheetsImportService::cleanMapping(['email' => 1, 'bogus' => 0, 'phone' => 9], 3));
    }

    public function test_id_token_email_and_service_list(): void
    {
        $payload = rtrim(strtr(base64_encode('{"email":"Box@Example.test"}'), '+/', '-_'), '=');
        $this->assertSame('box@example.test', GoogleConnectService::emailFromIdToken('h.'.$payload.'.s'));
        $this->assertNull(GoogleConnectService::emailFromIdToken('garbage'));
        $this->assertNull(GoogleConnectService::emailFromIdToken(null));
        $this->assertSame([GoogleService::Sheets, GoogleService::Gmail], GoogleService::parseList('sheets, gmail,drive,gmail'));
    }

    private static function b64(string $text): string
    {
        return rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
    }
}
