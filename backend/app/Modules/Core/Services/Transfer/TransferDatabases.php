<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Transfer;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/**
 * The two connections of `db:transfer-to-mysql`: source = PostgreSQL (Neon), target = MySQL 8.4.
 * URLs come only from config/db_transfer.php (env TRANSFER_SOURCE_URL / TRANSFER_TARGET_URL); host/user/password of
 * the app's own DB_* variables are never mixed in. Nothing here prints a URL or a password.
 */
final class TransferDatabases
{
    public const string SOURCE = 'transfer_source';

    public const string TARGET = 'transfer_target';

    /** Verdicts of appServerVerdict(). */
    public const string APP_NOT_MYSQL = 'skip';

    public const string APP_SAME = 'same';

    public const string APP_DIFFERENT = 'different';

    public const string APP_UNKNOWN = 'unknown';

    /**
     * Base of the source connection. The app runs only on MySQL (ADR 0011): this is the one place that still configures
     * PostgreSQL — the read-only source of the transfer. Neon requires SNI: run with libpq >= 14 (any current PHP image);
     * the vercel-php libpq workaround (endpoint id inside the password) left together with the Vercel runtime.
     *
     * @var array<string, mixed>
     */
    public const array SOURCE_BASE = [
        'driver' => 'pgsql',
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => 'prefer',
    ];

    private function __construct(public readonly Connection $source, public readonly Connection $target) {}

    /**
     * Builds both connections lazily (no network round-trip yet).
     *
     * @throws RuntimeException missing URL or a wrong driver on either side
     */
    public static function connect(DatabaseManager $db, Repository $config): self
    {
        $urls = [self::SOURCE => $config->get('db_transfer.source_url'), self::TARGET => $config->get('db_transfer.target_url')];
        foreach ($urls as $name => $url) {
            if (! is_string($url) || $url === '') {
                throw new RuntimeException(($name === self::SOURCE ? 'TRANSFER_SOURCE_URL' : 'TRANSFER_TARGET_URL')
                    .' не задан: строка подключения берётся только из окружения.');
            }
        }
        // Only the URL decides where we connect: blank out the app's DB_* values the base config was built from.
        // unix_socket too: with DB_SOCKET set PDO would ignore the URL host and talk to the app's local server.
        $blank = ['host' => null, 'port' => null, 'database' => null, 'username' => null, 'password' => null, 'unix_socket' => ''];
        $mysql = (array) $config->get('database.connections.mysql');
        $config->set('database.connections.'.self::SOURCE, array_merge(self::SOURCE_BASE, $blank, ['url' => $urls[self::SOURCE]]));
        $config->set('database.connections.'.self::TARGET, array_merge($mysql, $blank, ['url' => $urls[self::TARGET]]));
        $db->purge(self::SOURCE);
        $db->purge(self::TARGET);

        $source = $db->connection(self::SOURCE);
        $target = $db->connection(self::TARGET);
        if ($source->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Источник должен быть PostgreSQL (TRANSFER_SOURCE_URL postgresql://…).');
        }
        if ($target->getDriverName() !== 'mysql') {
            throw new RuntimeException('Цель должна быть MySQL (TRANSFER_TARGET_URL mysql://…).');
        }
        if ((string) $target->getConfig('unix_socket') !== '') {
            throw new RuntimeException('Цель через unix_socket не поддерживается: укажите хост и порт в TRANSFER_TARGET_URL.');
        }

        return new self($source, $target);
    }

    /** First real round-trip (after the launch guard): timestamptz is read as UTC; MySQL is +00:00 by its config. */
    public function open(): void
    {
        $this->source->statement("SET TIME ZONE 'UTC'");
        $this->target->select('select 1');
    }

    /** "driver host:port/database" without credentials — safe to print. */
    public static function describe(Connection $connection): string
    {
        return $connection->getDriverName().' '.(string) $connection->getConfig('host').':'
            .(string) ($connection->getConfig('port') ?? '-').'/'.$connection->getDatabaseName();
    }

    /** @return list<string> */
    public function hosts(): array
    {
        return [(string) $this->source->getConfig('host'), (string) $this->target->getConfig('host')];
    }

    /**
     * By configuration: the app's own MySQL connection points at the target (host after loopback normalisation —
     * localhost / 127.0.0.1 / ::1 / [::1] are one machine —, port, database name), or the app uses a unix socket
     * (a socket is always this machine's server, so it is treated as the same server).
     */
    public function targetIsAppDatabase(Connection $app): bool
    {
        if ($app->getDriverName() !== 'mysql' || $app->getDatabaseName() !== $this->target->getDatabaseName()) {
            return false;
        }
        if ((string) $app->getConfig('unix_socket') !== '') {
            return LaunchGuard::isLoopback((string) $this->target->getConfig('host'));
        }

        return LaunchGuard::normalizeHost((string) $app->getConfig('host')) === LaunchGuard::normalizeHost((string) $this->target->getConfig('host'))
            && (string) ($app->getConfig('port') ?? 3306) === (string) ($this->target->getConfig('port') ?? 3306);
    }

    /**
     * By the servers themselves (after the guard by configuration): is the app's MySQL connection the same server and
     * database as the target? Catches DNS aliases, proxies and port forwards. Fail-closed: when the app uses MySQL and
     * the comparison cannot be made (connection does not open, query fails), the verdict is APP_UNKNOWN and the caller
     * must refuse to write. Only an app on another driver (pgsql before the cutover) skips the check.
     *
     * Identity: @@server_uuid + database(); servers without @@server_uuid (MariaDB) — @@hostname + @@port + database().
     */
    public function appServerVerdict(Connection $app): string
    {
        if ($app->getDriverName() !== 'mysql') {
            return self::APP_NOT_MYSQL;
        }
        foreach (['select @@server_uuid as server, database() as db', "select concat(@@hostname, ':', @@port) as server, database() as db"] as $sql) {
            try {
                $mine = $app->selectOne($sql);
                $theirs = $this->target->selectOne($sql);
            } catch (Throwable) {
                continue; // try the next identity, finally fail closed
            }
            if (! is_object($mine) || ! is_object($theirs) || (string) $mine->server === '' || (string) $theirs->server === '') {
                continue;
            }

            return (string) $mine->server === (string) $theirs->server && (string) $mine->db === (string) $theirs->db
                ? self::APP_SAME
                : self::APP_DIFFERENT;
        }

        return self::APP_UNKNOWN;
    }
}
