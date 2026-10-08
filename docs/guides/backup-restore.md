# Резервирование и проверка восстановления

## Что уже доказуемо

Workflow **Backup restore proof** поднимает отдельный PostgreSQL 17 без production credentials.
Применяет реальные миграции, создаёт синтетические recruiter/branch/vacancy/candidate/application,
историю этапа и touchpoint, а также секрет через SecretVault. `pg_dump` и `pg_restore` запускаются внутри
того же контейнера PostgreSQL 17. Dump восстанавливается в другую пустую базу; исходная база удалена до проверки.
Проверяются связи, работа FK cascade, sequence и чтение ciphertext через SecretVault с тем же новым CI APP_KEY.
Dump удаляется trap, не загружается в artifacts; workflow не читает GitHub secrets и production данные.

Зелёный job доказывает восстановимость проверяемой схемы и синтетических связей. Он не подтверждает наличие
production backup, его свежесть, полный охват данных, доступность оператора или production RTO.
Длительность в job summary — только время маленькой синтетической CI-проверки.

## Решения владельца до production восстановления

| Решение | Статус |
|---|---|
| Допустимая потеря данных, RPO; частота backup/PITR | **OWNER PENDING** |
| Предельное время восстановления, RTO; условия измерения | **OWNER PENDING** |
| Retention DB backups, key versions, object storage versions; регион и отдельная копия | **OWNER PENDING** |
| Владелец backup, дежурный restore operator и запасной оператор | **OWNER PENDING** |
| Права чтения backup/ключа, MFA, break-glass и журнал доступа | **OWNER PENDING** |
| Частота restore drill, оповещение о провале/просроченном backup | **OWNER PENDING** |
| Согласование остановки записи и переключения production, критерии rollback | **OWNER PENDING** |

До закрытия этих пунктов readiness production backup остаётся незавершённой.

## Что сохранять

1. Согласованный snapshot/PITR или логический dump PostgreSQL с миграциями, данными и sequences.
   Зафиксировать время точки восстановления, версию PostgreSQL, SHA приложения и checksum файла в закрытом журнале.
   Хранить зашифрованно, с ограниченным доступом, вне публичного репозитория/CI artifacts; retention задаёт владелец.
2. Соответствующий **APP_KEY** и, при ротации, требуемые предыдущие версии ключа — отдельным защищённым каналом,
   с привязкой к backup point. Dump ciphertext без ключа не восстанавливает интеграции. Не менять ключ до проверки.
   Runtime DB credentials и другие bootstrap secrets восстанавливать через secret manager; не включать их в dump logs.
3. Инвентарь файлов и всех фактических storage adapters. Сейчас `documents_files` и `career_submissions`
   хранят содержимое в БД ([Documents](../modules/documents.md), [Recruiting](../modules/recruiting.md));
   внешние object storage, filesystem и файлы/ссылки у провайдеров требуют отдельного backup и сверки.
   CI gate их не проверяет. Для внешних объектов нужны версии, метаданные, checksum, права/KMS и согласованная с DB точка.

Наличие ссылки в восстановленной БД не доказывает доступность или восстановление файла.

## Изолированное восстановление оператором

1. Получить разрешённую точку восстановления и доступ по утверждённой процедуре. Проверить checksum,
   комплект DB + APP_KEY + внешние объекты, совместимость версии PostgreSQL и приложения.
   Использовать client той же major версии, что сервер источника, либо заранее проверенную совместимость.
2. Создать **новую пустую** изолированную БД и отдельное приложение без production routing.
   Отключить cron/jobs/queue workers, исходящие письма, webhooks, AI и остальные внешние вызовы; ограничить egress.
   Подключение target дважды проверить по host, database и окружению; production endpoint запрещён для restore drill.
3. Загрузить dump атомарно, с остановкой при ошибке. Для custom-format пример после настройки доступа в закрытом
   `PGPASSFILE` (chmod 600), `PGHOST`, `PGPORT`, `PGUSER`, `PGDATABASE` **нового target**:

   ```bash
   pg_restore --dbname="$PGDATABASE" --exit-on-error --single-transaction --no-owner --no-acl "$BACKUP_FILE"
   ```

   `--no-owner --no-acl` не переносит production права: назначить минимальные target-права отдельно и проверить их.
   Не использовать `--clean` против существующей БД. Если нужен дамп source, применять approved read-only connection:

   ```bash
   pg_dump --dbname="$PGDATABASE" --format=custom --no-owner --no-acl --file="$BACKUP_FILE"
   ```

   Перед этой командой отдельно выбрать и проверить **source**. Эти примеры — разные сессии/подключения;
   нельзя запускать их подряд с прежними target/source переменными. Доступ брать из secret manager,
   не передавать пароль в URI/аргументах, не включать shell tracing.
4. Установить SHA приложения и ключ, соответствующие backup. Не применять новую миграцию и не запускать
   синтетический seed на восстановленных реальных данных. Сверить миграции со схемой; изменение версии — отдельный план.
5. Проверить health/database, критические row counts по закрытому baseline, связи/FK/sequences и бизнес-инварианты,
   разрешения доступа; прочитать выбранный секрет через SecretVault с вердиктом «читается», без вывода значения.
   Для документов сверить метаданные и checksum содержимого, для внешних storage — существование/версии объектов.
   Проверять бизнес-сценарии с отключёнными внешними вызовами и журналировать только коды/счётчики.
6. Записать фактическую потерю данных и полный elapsed time, включая доступ, DB, ключ, файлы и приложение.
   Сравнить с утверждёнными RPO/RTO. Получить отдельное разрешение владельца на production cutover.

## Остановка и rollback

При несовпадении checksum, отсутствующем ключе/объектах, ошибке restore, нарушении связей или неизвестном target
**остановиться до переключения**. Сохранить закрытый диагностический журнал без содержимого/секретов;
не повторять автоматически against production. Повторить drill в новой пустой изолированной базе после устранения причины.

При разрешённом cutover сначала остановить записи/cron/queues по согласованному плану и сохранить контрольную точку.
Исходную production БД не удалять и не переписывать. Если smoke не пройден, остановить новые записи и вернуть
предыдущее приложение/маршрутизацию к сохранённому источнику. Записи, возникшие после переключения,
требуют отдельного reconciliation: нельзя обещать rollback без потери данных автоматически.

После drill удалить изолированную БД, приложение, временный dump и временный доступ; retained backups/ключи
удалять только по утверждённой retention policy. Проверку production восстановления и внешних файлов согласовать отдельно.

## Synthetic MySQL transfer rehearsal

`synthetic-mysql-transfer` in `.github/workflows/backup-restore.yml` uses disposable PostgreSQL 17 and MySQL 8.4 service databases and one generated application key. It migrates both schemas, copies a synthetic linked fixture without clearing either database, and checks row counts, foreign keys, IDs, Unicode/JSON, long text, the full 2 MiB database attachment and its SHA-256, and encrypted-vault ciphertext. It also replays one new candidate from the target to the retained source and advances the source sequence. The script accepts only fixed local CI databases and does not accept production connection strings.

This is a compatibility rehearsal. A live cutover still needs verified source backup/restore, target version and TLS checks, a complete data comparison, write quiescence or change capture, and a rollback plan covering updates and deletes as well as new rows. Do not run a reset or point Vercel at the target based on this CI job alone.

## MySQL 8.4 synthetic backup and isolated restore (HRM-38)

The `synthetic-mysql-restore` job in `backup-restore.yml` runs only against the fixed disposable MySQL 8.4 service. It migrates an empty source, generates one CI `APP_KEY`, seeds linked recruiting and employee rows, a 2 MiB database attachment, and an encrypted `integration_secrets` value. `mysqldump` uses the matching service client with `--single-transaction --routines --triggers --events --hex-blob --no-tablespaces --set-gtid-purged=OFF`. The job records exact per-table row counts, creates a separate target database, removes the synthetic source, restores the dump, compares the table/count inventory, and checks relationships, foreign-key cascade, next `AUTO_INCREMENT`, attachment bytes/SHA-256 and decryption under the unchanged key. The dump and inventory files are removed by a shell trap and are never uploaded as artifacts. The fixed host/database/CI guards reject production URLs and local execution.

This job proves a synthetic logical dump and restore, not a production backup, PITR, RPO/RTO, or a Neon-to-MySQL transfer. Keep the PostgreSQL proof during the dual-support period. Before using the procedure on IT STEP, DevOps must confirm MySQL 8.4, InnoDB-only tables or a write/DDL freeze for a consistent snapshot, backup privileges (including routines/triggers), TLS certificate validation, encrypted storage and retention, the separate restore destination, operator roles, and the owner-approved RPO/RTO. Never run this CI script against a live database.

For a real drill, use a protected client option file supplied by IT STEP's secret manager (mode `0600`), with separate source and restore accounts. Confirm the source and target host/database names independently before running commands. Run the matching MySQL 8.4 client on a trusted host, with server certificate validation required by IT STEP. A representative logical backup and isolated restore are:

```bash
# Paths, endpoints and database names come from the approved runbook; no passwords on the command line.
umask 077
mysqldump --defaults-extra-file="$SOURCE_CLIENT_CNF" --single-transaction --routines --triggers --events \
  --hex-blob --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 \
  --result-file="$BACKUP_FILE" "$SOURCE_DATABASE"
sha256sum "$BACKUP_FILE" > "$BACKUP_SHA_FILE"
sha256sum --check "$BACKUP_SHA_FILE"
# Provision a NEW isolated restore database through the approved operator workflow first.
mysql --defaults-extra-file="$RESTORE_CLIENT_CNF" --database="$RESTORE_DATABASE" < "$BACKUP_FILE"
```

Do not use `--add-drop-database`, `--all-databases`, `--force`, or a restore target that is in use. `--defaults-extra-file` must be the first client option. Verify exact table/row counts, primary/foreign keys, next auto-increments, attachment bytes and SHA-256, and encrypted-vault decryption with the retained `APP_KEY` in the isolated restore. Compare the measured elapsed time and restore point with the approved RTO/RPO; preserve the source and record operator, backup hash, MySQL version, test results and exceptions without logging personal data or secrets. The live cutover still requires the separate HRM-37 transfer and rollback rehearsal.
