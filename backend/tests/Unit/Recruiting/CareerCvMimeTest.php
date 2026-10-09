<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Modules\Recruiting\Services\CareerSiteService;
use PHPUnit\Framework\TestCase;

/** CV type is decided by the bytes (upload validation and the recruiter download share it). */
final class CareerCvMimeTest extends TestCase
{
    public function test_pdf_is_recognised_by_bytes_whatever_the_name(): void
    {
        $this->assertSame('application/pdf', CareerSiteService::cvMime("%PDF-1.4\n%%EOF", 'cv.docx'));
    }

    public function test_html_or_plain_text_named_pdf_is_refused(): void
    {
        $this->assertNull(CareerSiteService::cvMime('<html><script>alert(1)</script></html>', 'cv.pdf'));
        $this->assertNull(CareerSiteService::cvMime('just text', 'cv.pdf'));
    }

    public function test_image_is_refused(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertIsString($png);
        $this->assertNull(CareerSiteService::cvMime($png, 'cv.png'));
    }
}
