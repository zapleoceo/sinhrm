<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Transfer;

use App\Modules\Core\Services\Transfer\KeyCheck;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\TestCase;

final class KeyCheckTest extends TestCase
{
    public function test_counts_values_that_do_not_decrypt_with_the_environment_key(): void
    {
        $production = new Encrypter(random_bytes(32), 'aes-256-cbc');
        $other = new Encrypter(random_bytes(32), 'aes-256-cbc');
        $values = [$production->encryptString('one'), $production->encryptString('two'), $other->encryptString('three'), null];

        $this->assertSame(['checked' => 3, 'failed' => 1], KeyCheck::count($production, $values));
        $this->assertSame(['checked' => 3, 'failed' => 2], KeyCheck::count($other, $values));
    }
}
