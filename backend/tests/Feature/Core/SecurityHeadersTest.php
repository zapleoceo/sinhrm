<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Modules\Core\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_carry_security_headers(): void
    {
        foreach (['/api/health', '/api/errors'] as $url) {
            $response = $this->getJson($url);
            foreach (SecurityHeaders::HEADERS as $name => $value) {
                $response->assertHeader($name, $value);
            }
            $response->assertHeader('Content-Security-Policy', SecurityHeaders::API_CSP);
        }
    }

    public function test_api_docs_ui_keeps_its_scripts(): void
    {
        $this->get('/api/docs')->assertHeaderMissing('Content-Security-Policy')->assertHeader('X-Frame-Options', 'DENY');
    }
}
