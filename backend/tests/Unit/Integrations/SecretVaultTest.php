<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Modules\Integrations\Contracts\SecretVault;
use App\Modules\Integrations\DTO\SecretMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SecretVaultTest extends TestCase
{
    use RefreshDatabase;

    private const string VALUE = 'fake-vault-secret-0042';

    public function test_value_is_encrypted_at_rest_and_decrypted_on_get(): void
    {
        $vault = $this->app->make(SecretVault::class);
        $vault->put('viber', 'token', self::VALUE, null);

        $raw = (string) DB::table('integration_secrets')->value('value');
        $this->assertNotSame(self::VALUE, $raw);
        $this->assertStringNotContainsString('fake-vault', $raw);
        $this->assertSame(self::VALUE, Crypt::decryptString($raw));
        $this->assertSame(self::VALUE, $vault->get('viber', 'token'));
    }

    public function test_put_overwrites_forget_deletes_and_missing_is_null(): void
    {
        $vault = $this->app->make(SecretVault::class);
        $this->assertNull($vault->get('viber', 'token'));

        $vault->put('viber', 'token', 'first-value-1111');
        $vault->put('viber', 'token', 'second-value-2222');
        $this->assertSame('second-value-2222', $vault->get('viber', 'token'));
        $this->assertSame(1, DB::table('integration_secrets')->count());

        $vault->forget('viber', 'token');
        $this->assertNull($vault->get('viber', 'token'));
        $this->assertSame([], $vault->describe('viber'));
    }

    public function test_describe_returns_only_masked_tail(): void
    {
        $vault = $this->app->make(SecretVault::class);
        $vault->put('viber', 'token', self::VALUE);

        $meta = $vault->describe('viber')['token'];
        $this->assertTrue($meta->isSet);
        $this->assertSame('••••0042', $meta->masked);
    }

    public function test_mask_never_reveals_more_than_a_quarter(): void
    {
        $this->assertSame('••••', SecretMeta::mask('abc'));
        $this->assertSame('••••gh', SecretMeta::mask('abcdefgh'));
        $this->assertSame('••••mnop', SecretMeta::mask('abcdefghijklmnop'));
        $this->assertSame('••••wxyz', SecretMeta::mask('abcdefghijklmnopqrstuvwxyz'));
    }
}
