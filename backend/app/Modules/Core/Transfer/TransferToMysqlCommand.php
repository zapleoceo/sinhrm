<?php

declare(strict_types=1);

namespace App\Modules\Core\Transfer;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * PostgreSQL (Neon) -> MySQL 8.4 data transfer for the cutover (ADR 0010, PROD-47). Runbook:
 * docs/guides/mysql-cutover.md. Modes: --preflight (read-only checks), --verify (read-only reconciliation),
 * default = preflight -> confirmation -> copy -> reconciliation. Connections only from env TRANSFER_SOURCE_URL /
 * TRANSFER_TARGET_URL; output never contains URLs, passwords or cell values.
 * REMOVE AFTER CUTOVER together with the whole Core/Transfer directory (see TransferServiceProvider).
 */
final class TransferToMysqlCommand extends Command
{
    protected $signature = 'db:transfer-to-mysql
        {--preflight : Только проверки, без записи}
        {--verify : Только сверка источника и цели, без записи}
        {--truncate-target : Очистить таблицы цели перед переносом (кроме migrations)}
        {--confirm-target= : Имя целевой БД — подтверждение записи без диалога}
        {--production : Явное разрешение работать с боевыми (не локальными) базами}';

    protected $description = 'Перенос данных PostgreSQL -> MySQL 8.4 с preflight и сверкой (docs/guides/mysql-cutover.md)';

    public function handle(DatabaseManager $db, Repository $config): int
    {
        $secrets = $this->secrets($config);
        if ($this->option('preflight') && $this->option('verify')) {
            $this->error('--preflight и --verify взаимоисключающие.');

            return self::FAILURE;
        }
        $writes = ! $this->option('preflight') && ! $this->option('verify');

        try {
            $dbs = TransferDatabases::connect($db, $config);
            $this->line('Источник: '.TransferDatabases::describe($dbs->source));
            $this->line('Цель:     '.TransferDatabases::describe($dbs->target));
            if (LaunchGuard::needsProductionFlag((string) $config->get('app.env'), $dbs->hosts()) && ! $this->option('production')) {
                $this->error('Боевое окружение или не локальная база: запуск только с явным флагом --production.');

                return self::FAILURE;
            }
            if ($writes && $dbs->targetIsAppDatabase($db->connection())) {
                $this->error('Цель совпадает с рабочей БД приложения (DB_URL/DB_*): перенос в живую базу запрещён.');

                return self::FAILURE;
            }
            $dbs->open();
            $verdict = $writes ? $dbs->appServerVerdict($db->connection()) : TransferDatabases::APP_SKIPPED;
            if ($verdict === TransferDatabases::APP_SAME) {
                $this->error('Цель — тот же сервер MySQL и та же база, что у приложения (@@server_uuid): перенос в живую базу запрещён.');

                return self::FAILURE;
            }
            if ($verdict === TransferDatabases::APP_UNKNOWN) {
                $this->error('Не удалось сравнить цель с MySQL-подключением приложения (DB_CONNECTION=mysql, DB_URL/DB_*): перенос запрещён. '
                    .'Проверьте доступность БД приложения (отдельная база MySQL рядом с целью, см. docs/guides/mysql-cutover.md).');

                return self::FAILURE;
            }
            $schema = new SchemaInspector($dbs);
            $chunk = max(1, (int) $config->get('db_transfer.chunk', 500));

            if ($this->option('verify')) {
                return $this->verify(new Reconciler($dbs, $schema, $chunk));
            }

            $this->info('Preflight (без записи)…');
            $preflight = (new Preflight($dbs, $schema, new MysqlCollationKeys($dbs->target), $chunk))
                ->run(fn (): StringEncrypter => $this->laravel->make('encrypter'), $writes && (bool) $this->option('truncate-target'));
            $this->printFindings($preflight);
            if (! $preflight->ok()) {
                $this->error('Preflight: FAIL — перенос не начат. Исправьте данные в источнике (или схему миграцией) и повторите.');

                return self::FAILURE;
            }
            $this->info('Preflight: OK');
            if (! $writes) {
                return self::SUCCESS;
            }

            $database = $dbs->target->getDatabaseName();
            $typed = $this->option('confirm-target');
            if ($typed === null && $this->input->isInteractive()) {
                $typed = $this->ask(($this->option('truncate-target') ? 'ВСЕ таблицы цели будут ОЧИЩЕНЫ. ' : '')
                    ."Запись в {$database}. Введите имя целевой базы для подтверждения");
            }
            if (! LaunchGuard::confirmed(is_string($typed) ? $typed : null, $database)) {
                $this->error('Нет подтверждения: укажите имя целевой базы (диалог или --confirm-target=<имя>). Ничего не записано.');

                return self::FAILURE;
            }

            $this->info('Перенос…');
            $copied = (new DataCopier($dbs, $schema, $chunk))->copy(
                (bool) $this->option('truncate-target'),
                function (string $table, int $now, int $total): void {
                    $this->line(sprintf('  %-40s +%d (в источнике %d)', $table, $now, $total));
                },
            );
            $this->info('Скопировано строк: '.array_sum($copied));
            if (SchemaCheck::requeuePostFreeze($schema) > 0) {
                $this->warn('Миграции данных после заморозки сняты с учёта на цели: выполните `php artisan migrate --force` (docs/guides/mysql-cutover.md).');
            }

            return $this->verify(new Reconciler($dbs, $schema, $chunk));
        } catch (Throwable $e) {
            $this->error('Остановлено: '.SafeError::text($e, $secrets));
            $this->line('Повторный запуск безопасен: уже перенесённые строки пропускаются (или --truncate-target).');

            return self::FAILURE;
        }
    }

    private function verify(Reconciler $reconciler): int
    {
        $this->info('Сверка (без записи)…');
        $report = $reconciler->run();
        $this->table(['Таблица', 'Источник', 'Цель', 'Статус'], array_map(
            fn (array $t): array => [$t['table'], $t['source'], $t['target'], $t['ok'] ? 'OK' : 'FAIL'],
            $report->tables(),
        ));
        $this->printFindings($report);
        if ($report->ok()) {
            $this->info('ИТОГ: OK');

            return self::SUCCESS;
        }
        $this->error('ИТОГ: FAIL ('.count($report->blocking()).' проблем)');

        return self::FAILURE;
    }

    private function printFindings(TransferReport $report): void
    {
        if ($report->findings() === []) {
            return;
        }
        $this->table(['', 'Проверка', 'Таблица', 'Что', 'Кол-во'], array_map(
            fn (array $f): array => [$f['blocking'] ? 'FAIL' : 'info', $f['check'], $f['table'], $f['detail'], $f['count']],
            $report->findings(),
        ));
    }

    /** @return list<string> URLs and passwords to mask in any error text */
    private function secrets(Repository $config): array
    {
        $secrets = [];
        foreach (['db_transfer.source_url', 'db_transfer.target_url'] as $key) {
            $url = $config->get($key);
            if (! is_string($url) || $url === '') {
                continue;
            }
            $secrets[] = $url;
            $password = parse_url($url, PHP_URL_PASS);
            if (is_string($password) && $password !== '') {
                $secrets[] = $password;
                $secrets[] = rawurldecode($password);
            }
        }

        return $secrets;
    }
}
