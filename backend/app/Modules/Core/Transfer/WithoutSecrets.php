<?php

declare(strict_types=1);

namespace App\Modules\Core\Transfer;

/**
 * `--without-secrets`: a transfer for test data / a dump handed to third parties (DevOps) when the production APP_KEY is
 * not available or the secrets must not leave. Every table encrypted with APP_KEY (KeyCheck::ENCRYPTED) is NOT copied
 * and stays empty on the target, so nothing has to be decrypted and the APP_KEY check is not run. Never for the
 * production cutover: the app would lose every integration token (docs/guides/mysql-cutover.md).
 *
 * The rules are pure (counters in, findings out) so they are unit-tested without databases; Preflight, Reconciler and
 * the command only feed them row counts. Findings carry table names and counters only, never values.
 */
final class WithoutSecrets
{
    public const string CHECK = 'without_secrets';

    /** @return list<string> tables left out of the transfer ([] when the option is off — the default, fail-closed) */
    public static function tables(bool $enabled): array
    {
        return $enabled ? array_keys(KeyCheck::ENCRYPTED) : [];
    }

    /**
     * Preflight: a skipped table must end up empty. With --truncate-target the copy empties it; otherwise rows already on
     * the target (an earlier full run) would survive next to the test data — blocking.
     */
    public static function preflight(string $table, int $source, int $target, bool $truncate, TransferReport $report): void
    {
        if (! $truncate && $target > 0) {
            $report->fail(self::CHECK, $table, "в цели уже {$target} строк — с --without-secrets таблица должна остаться пустой: перенос только с --truncate-target", $target);

            return;
        }
        $report->note(self::CHECK, $table, "не копируется (--without-secrets): в источнике {$source} строк, на цели останется пусто", $source);
    }

    /** Reconciliation: a skipped table is expected to be EMPTY on the target; returns whether it is. */
    public static function reconcile(string $table, int $source, int $target, TransferReport $report): bool
    {
        if ($target > 0) {
            $report->fail(self::CHECK, $table, "--without-secrets: на цели {$target} строк, ожидается 0", $target);

            return false;
        }
        $report->note(self::CHECK, $table, "не перенесена (--without-secrets): в источнике {$source} строк, на цели 0", $source);

        return true;
    }

    /** @param  array<string, int>  $sourceCounts  skipped table => rows on the source */
    public static function summary(array $sourceCounts): string
    {
        $parts = [];
        foreach ($sourceCounts as $table => $count) {
            $parts[] = "{$table} (в источнике {$count} строк)";
        }

        return 'Пропущены таблицы (--without-secrets, на цели пусто): '.($parts === [] ? 'нет' : implode(', ', $parts));
    }
}
