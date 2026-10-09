<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Transfer;

use App\Modules\Core\Transfer\KeyCheck;
use FilesystemIterator;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

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

    /**
     * KeyCheck::ENCRYPTED is both the APP_KEY gate and the --without-secrets skip list: every model of app/Modules that
     * encrypts with APP_KEY must be listed there, or a test dump would carry its ciphertexts.
     */
    public function test_every_app_key_encrypted_table_is_listed(): void
    {
        $modules = dirname(__DIR__, 4).'/app/Modules';
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modules, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = str_replace('\\', '/', (string) $file);
            if (! str_ends_with($path, '.php') || str_contains($path, '/Core/Transfer/')) {
                continue;
            }
            $code = (string) file_get_contents($path);
            if (preg_match("/=>\\s*'encrypted(:[^']*)?'|AsEncrypted\\w+|Crypt::|encryptString\\(/", $code) !== 1) {
                continue;
            }
            $this->assertMatchesRegularExpression("/protected \\\$table = '(\\w+)'/", $code, "{$path} encrypts with APP_KEY: declare \$table and add it to KeyCheck::ENCRYPTED");
            preg_match("/protected \\\$table = '(\\w+)'/", $code, $m);
            $found[] = $m[1];
        }

        $this->assertContains('integration_secrets', $found);
        foreach ($found as $table) {
            $this->assertArrayHasKey($table, KeyCheck::ENCRYPTED, "{$table} is encrypted with APP_KEY but missing in KeyCheck::ENCRYPTED");
        }
    }
}
