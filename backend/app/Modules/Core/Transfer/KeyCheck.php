<?php

declare(strict_types=1);

namespace App\Modules\Core\Transfer;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Connection;
use Throwable;

/**
 * "Same APP_KEY" gate: ciphertexts are copied byte for byte, so after the switch the app can read them only with the
 * key it used on Neon. Every encrypted value of the source must decrypt with the key of THIS environment
 * (APP_KEY + APP_PREVIOUS_KEYS); decrypted values are discarded at once, never printed or stored.
 */
final class KeyCheck
{
    /**
     * table => columns written through the Eloquent "encrypted" cast (or Crypt). Also the list of tables that
     * `--without-secrets` leaves empty (WithoutSecrets::tables); KeyCheckTest fails when a new encrypted cast is missing.
     */
    public const array ENCRYPTED = ['integration_secrets' => ['value']];

    /**
     * @param  iterable<string|null>  $ciphertexts
     * @return array{checked: int, failed: int}
     */
    public static function count(StringEncrypter $encrypter, iterable $ciphertexts): array
    {
        $checked = 0;
        $failed = 0;
        foreach ($ciphertexts as $ciphertext) {
            if ($ciphertext === null) {
                continue;
            }
            $checked++;
            try {
                $encrypter->decryptString($ciphertext);
            } catch (DecryptException) {
                $failed++;
            }
        }

        return ['checked' => $checked, 'failed' => $failed];
    }

    /**
     * Adds a blocking finding per table/column whose values do not decrypt; a missing APP_KEY blocks too.
     *
     * @param  callable(): StringEncrypter  $encrypter  resolved lazily: a missing key throws on resolution
     */
    public static function check(Connection $source, callable $encrypter, TransferReport $report): void
    {
        try {
            $instance = $encrypter();
        } catch (Throwable) {
            $report->fail('app_key', '-', 'APP_KEY не задан или неверного формата в окружении');

            return;
        }
        foreach (self::ENCRYPTED as $table => $columns) {
            if (! $source->getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                $values = $source->table($table)->select(['id', $column])->lazyById(500, 'id')
                    ->map(fn (object $row): ?string => $row->{$column} === null ? null : (string) $row->{$column});
                $result = self::count($instance, $values);
                if ($result['failed'] > 0) {
                    $report->fail('app_key', $table, "{$column}: {$result['failed']} из {$result['checked']} значений не расшифровываются APP_KEY окружения — нужен тот же ключ, что у прода", $result['failed']);
                } else {
                    $report->note('app_key', $table, "{$column}: {$result['checked']} значений расшифровываются ключом окружения", $result['checked']);
                }
            }
        }
    }
}
