<?php

declare(strict_types=1);

namespace Tests\Unit\Recruiting;

use App\Modules\Recruiting\Support\ContactNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContactNormalizerTest extends TestCase
{
    /** @return iterable<string, array{0: string|null, 1: string|null}> */
    public static function phones(): iterable
    {
        yield 'national with spaces' => ['067 123 45 67', '+380671234567'];
        yield 'national with dashes and brackets' => ['(067) 123-45-67', '+380671234567'];
        yield 'already e164' => ['+380671234567', '+380671234567'];
        yield 'country code without plus' => ['380671234567', '+380671234567'];
        yield 'old 8 prefix' => ['80671234567', '+380671234567'];
        yield 'nine digits' => ['671234567', '+380671234567'];
        yield 'international 00' => ['0048 601 234 567', '+48601234567'];
        yield 'foreign with plus' => ['+1 (212) 555-0100', '+12125550100'];
        yield 'too short' => ['12345', null];
        yield 'too long' => ['+1234567890123456', null];
        yield 'letters only' => ['call me', null];
        yield 'empty' => ['  ', null];
        yield 'null' => [null, null];
    }

    #[DataProvider('phones')]
    public function test_phone(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, (new ContactNormalizer)->phone($raw));
    }

    public function test_email_and_telegram(): void
    {
        $n = new ContactNormalizer;
        $this->assertSame('a.b@example.test', $n->email('  A.B@Example.TEST '));
        $this->assertNull($n->email('not-an-email'));
        $this->assertNull($n->email(null));
        $this->assertSame('some_user', $n->telegram('@Some_User'));
        $this->assertSame('some_user', $n->telegram('https://t.me/some_user'));
        $this->assertSame('some_user', $n->telegram('t.me/Some_User'));
        $this->assertNull($n->telegram('@ab'));
        $this->assertNull($n->telegram('bad name!'));
    }

    public function test_guess_detects_contact_type(): void
    {
        $n = new ContactNormalizer;
        $this->assertSame('x@example.test', $n->guess('X@example.test')->email);
        $this->assertSame('handle_1', $n->guess('@handle_1')->telegram);
        $this->assertSame('handle_1', $n->guess('https://t.me/handle_1')->telegram);
        $this->assertSame('+380931112233', $n->guess('093 111 22 33')->phone);
        $this->assertTrue($n->guess('')->isEmpty());
        $this->assertTrue($n->guess(null)->isEmpty());
    }
}
