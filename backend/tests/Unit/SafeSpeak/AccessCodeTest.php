<?php

declare(strict_types=1);

namespace Tests\Unit\SafeSpeak;

use App\Modules\SafeSpeak\Support\AccessCode;
use App\Modules\SafeSpeak\Support\ClientBucket;
use PHPUnit\Framework\TestCase;

final class AccessCodeTest extends TestCase
{
    private const string KEY = 'synthetic-app-key';

    public function test_codes_are_random_well_formed_and_hashed_with_the_key(): void
    {
        $a = AccessCode::generate();
        $b = AccessCode::generate();
        $this->assertNotSame($a, $b);
        $this->assertTrue(AccessCode::wellFormed($a));
        $this->assertSame(64, strlen(AccessCode::hash($a, self::KEY)));
        $this->assertNotSame(AccessCode::hash($a, self::KEY), AccessCode::hash($a, 'synthetic-other-key'));
        // Typing variants resolve to the same code: lower case, spaces, O/I/L look-alikes.
        $this->assertSame(AccessCode::hash('0123-ABCD-EFGH-1JKM', self::KEY), AccessCode::hash('o123 abcd efgh ljkm', self::KEY));
        $this->assertFalse(AccessCode::wellFormed('short'));
        $this->assertFalse(AccessCode::wellFormed('UUUU-UUUU-UUUU-UUUU'));
    }

    public function test_client_bucket_hides_the_address(): void
    {
        $bucket = ClientBucket::of('203.0.113.5', self::KEY);
        $this->assertSame(32, strlen($bucket));
        $this->assertStringNotContainsString('203.0.113.5', $bucket);
        $this->assertSame($bucket, ClientBucket::of('203.0.113.5', self::KEY));
        $this->assertNotSame($bucket, ClientBucket::of('203.0.113.6', self::KEY));
    }
}
