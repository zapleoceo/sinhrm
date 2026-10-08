<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Transfer;

use App\Modules\Core\Support\NeonConnectionConfig;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * The two connections of `db:transfer-to-mysql`: source = PostgreSQL (Neon), target = MySQL 8.4.
 * URLs come only from config/db_transfer.php (env TRANSFER_SOURCE_URL / TRANSFER_TARGET_URL); host/user/password of
 * the app's own DB_* variables are never mixed in. Nothing here prints a URL or a password.
 */
final class TransferDatabases
{
    public const string SOURCE = 'transfer_source';

    public const string TARGET = 'transfer_target';

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
        $blank = ['host' => null, 'port' => null, 'database' => null, 'username' => null, 'password' => null];
        $pgsql = (array) $config->get('database.connections.pgsql');
        $mysql = (array) $config->get('database.connections.mysql');
        $config->set('database.connections.'.self::SOURCE, NeonConnectionConfig::apply(array_merge($pgsql, $blank, ['url' => $urls[self::SOURCE]])));
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

    /** True when the target is the database the application itself is configured to use right now. */
    public function targetIsAppDatabase(Connection $app): bool
    {
        return $app->getDriverName() === 'mysql'
            && (string) $app->getConfig('host') === (string) $this->target->getConfig('host')
            && (string) ($app->getConfig('port') ?? 3306) === (string) ($this->target->getConfig('port') ?? 3306)
            && $app->getDatabaseName() === $this->target->getDatabaseName();
    }
}
