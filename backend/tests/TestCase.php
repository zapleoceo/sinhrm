<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach the real network: an outgoing request without a matching Http::fake() throws
        // StrayRequestException instead of calling a provider (AI broker, Google, messengers, webhooks).
        Http::preventStrayRequests();
    }
}
