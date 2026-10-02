<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Modules\Core\Http\Responses\Download;
use PHPUnit\Framework\TestCase;

/** Downloads are attachments that the browser never renders on our origin. */
final class DownloadTest extends TestCase
{
    public function test_a_stored_file_is_an_attachment_with_an_ascii_fallback_name(): void
    {
        $response = Download::file('%PDF-1.7', 'Договір 2026.pdf', 'application/pdf', 8);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('%PDF-1.7', $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('8', $response->headers->get('Content-Length'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith("attachment; filename=_2026.pdf; filename*=utf-8''", $disposition);
        $this->assertStringContainsString(rawurlencode('Договір 2026.pdf'), $disposition);
    }

    public function test_an_ascii_name_needs_no_extended_parameter(): void
    {
        $response = Download::file('x', 'report-1.txt', 'text/plain', 1);

        $this->assertSame('attachment; filename=report-1.txt', $response->headers->get('Content-Disposition'));
    }

    public function test_disposition_of_our_own_file_name_is_quoted(): void
    {
        $this->assertSame('attachment; filename="employees.csv"', Download::disposition('employees.csv'));
    }
}
