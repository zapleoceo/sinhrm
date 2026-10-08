# Резервирование и проверка восстановления

## Что уже доказуемо

> **2026-10-08 (HRM-40, [ADR 0011](../adr/0011-mysql-only.md)): PostgreSQL-проверка снята.** Workflow
> `.github/workflows/backup-restore.yml` (jobs `synthetic-restore` и `synthetic-mysql-transfer`) удалён: `main` больше не
> строит схему PostgreSQL. MySQL-вариант (`mysqldump` + изолированное восстановление) — HRM-38, PR #174; репетиция
> переноса PostgreSQL → MySQL — workflow `mysql-data-transfer.yml`. Текст ниже описывает снятую PostgreSQL-проверку.

Workflow **Backup restore proof** поднимал отдельный PostgreSQL 17 без production credentials.
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

## Synthetic MySQL transfer rehearsal (removed 2026-10-08)

Removed with PostgreSQL support (ADR 0011); the transfer is rehearsed by `.github/workflows/mysql-data-transfer.yml`. Historical description: `synthetic-mysql-transfer` in `.github/workflows/backup-restore.yml` used disposable PostgreSQL 17 and MySQL 8.4 service databases and one generated application key. It migrates both schemas, copies a synthetic linked fixture without clearing either database, and checks row counts, foreign keys, IDs, Unicode/JSON, long text, the full 2 MiB database attachment and its SHA-256, and encrypted-vault ciphertext. It also replays one new candidate from the target to the retained source and advances the source sequence. The script accepts only fixed local CI databases and does not accept production connection strings.

This is a compatibility rehearsal. A live cutover still needs verified source backup/restore, target version and TLS checks, a complete data comparison, write quiescence or change capture, and a rollback plan covering updates and deletes as well as new rows. Do not run a reset or point Vercel at the target based on this CI job alone.
