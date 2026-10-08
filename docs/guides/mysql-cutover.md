# Переезд Neon (PostgreSQL) → MySQL 8.4: перенос данных, переключение, откат

Решение — [ADR 0010](../adr/0010-mysql-dual-support.md) и [ADR 0011](../adr/0011-mysql-only.md) (с 2026-10-08 `main` — только MySQL; боевой Vercel + Neon заморожен на ветке `legacy/vercel-postgres`), задача PROD-47 ([production-backlog.md](../product/production-backlog.md)).
Инструмент — команда `php artisan db:transfer-to-mysql`. Это **единственный PostgreSQL-код репозитория**, собранный в одном месте ради одного удаления после переезда: код — `backend/app/Modules/Core/Transfer/`, тесты — `backend/tests/{Unit,Feature}/Core/Transfer/`, workflow — `.github/workflows/mysql-data-transfer.yml` ([удаление после cutover](#удаление-после-cutover)). CI-страж `scripts/mysql-only-guard.mjs` (job `lint`) не пускает PostgreSQL за эти пределы.
Доказательство на синтетике — workflow `MySQL data transfer` (`.github/workflows/mysql-data-transfer.yml`, необязательный).

## Замороженный боевой релиз до cutover

До переезда боевой сайт работает на Vercel + Neon (PostgreSQL) из ветки `legacy/vercel-postgres` (коммит `8875ac4e`);
`main` туда не выкладывается (заморозка `VERCEL_DEPLOY_ENABLED`, [deploy.md](deploy.md#заморозка-vercel)). Хотфикс —
вручную из этой ветки по инструкции deploy.md. Ветку и Neon удаляет владелец после cutover.

## Что делает команда

| Режим | Пишет? | Что |
|---|---|---|
| `--preflight` | нет | схемы совпадают (миграции, таблицы, колонки); сессия MySQL `time_zone=+00:00` и строгий `sql_mode`; все значения `integration_secrets.value` расшифровываются `APP_KEY` окружения; **коллизии уникальных значений** под collation цели (`utf8mb4_0900_ai_ci`: регистр и диакритика — `a@x`/`A@x`, `jose`/`josé`; ключи сравнения считает сам MySQL через `WEIGHT_STRING`, поэтому отчёт совпадает с поведением сервера: замер на 8.4 — `Йосип`/`Иосип` **различаются**, у `Й` свой первичный вес); даты вне диапазона `TIMESTAMP` (до 1970 и после 2038-01-19); невалидный для MySQL JSON; строки длиннее колонок MySQL; строки больше `max_allowed_packet` |
| без флага | да | preflight → подтверждение → перенос → сверка. Любая блокирующая находка preflight — перенос **не начинается** (флага «игнорировать» нет и не будет) |
| `--verify` | нет | сверка: строки по таблицам, контрольные суммы каждой колонки, SHA-256 вложений, сироты FK, `AUTO_INCREMENT = max(id)+1`; итог `ИТОГ: OK` / `ИТОГ: FAIL` |

Перенос: порциями по первичному ключу, id сохраняются, `FOREIGN_KEY_CHECKS=0` только в сессии переноса (циклы вроде
`scripts ↔ script_versions` не требуют порядка) и проверка сирот после. Сессия MySQL — из соединения `mysql`
(`utf8mb4_0900_ai_ci`, строгий `sql_mode`, `+00:00`); `timestamptz` читается в UTC. Вложения (`documents_files`,
`desk_attachments`, CV кандидатов — base64 в `longText`) и JSON копируются как есть; JSON сверяется как данные (MySQL
переупорядочивает ключи). Шифротексты не перешифровываются — поэтому нужен **тот же `APP_KEY`**.

**Повторный запуск безопасен.** Строка, чей первичный ключ уже есть в цели, пропускается: после сбоя запуск продолжает
с места остановки, второй запуск копирует 0 строк. Без `--truncate-target` preflight требует, чтобы всё, что уже лежит
в цели, было частью источника (те же ключи, то же содержимое) — иначе стоп `target_not_empty` (чужие строки, базовые
строки миграций с другими датами, правки после прошлого прогона). Первый и финальный перенос — всегда с
`--truncate-target` (очистить все таблицы цели, кроме `migrations`).

**Обрыв соединения.** `FOREIGN_KEY_CHECKS=0` действует только в сессии переноса; Laravel не переподключается посреди
запроса, а новая сессия начинается с проверками FK — поэтому после обрыва вставка падает громко (`Остановлено: …`), а не
продолжается без проверок; повторный запуск безопасен. Порцию задаёт `TRANSFER_CHUNK` (по умолчанию 500 строк; таблицы
с вложениями — по 4 строки).

**`AUTO_INCREMENT = max(id)+1`.** Как и в PostgreSQL после `setval`, удалённые «хвостовые» id (больше текущего максимума)
после переноса будут выданы снова. Внешние ссылки на удалённые записи (письма, ссылки в мессенджерах) после переезда
могут указать на новую запись — это то же поведение, что у последовательностей Neon при восстановлении из дампа.

**Вывод** содержит только имена таблиц/колонок/индексов, счётчики и целочисленные id строк (у коллизий; для текстовых
ключей вроде `password_reset_tokens.email` — только номер строки) — без значений ячеек, ПДн, строк подключения и
паролей. Индекс по выражению на коллизии не проверяется — в отчёте отдельная строка `info`.

## Защита от случайного запуска

- Подключения — только из окружения: `TRANSFER_SOURCE_URL` (`postgresql://…?sslmode=require`) и `TRANSFER_TARGET_URL`
  (`mysql://…`). Аргументов со строкой подключения или паролем нет; URL и пароль не печатаются (маскируются и в ошибках).
- `--production` обязателен, если `APP_ENV=production` или хоть одна база не на `127.0.0.1/localhost`.
- Любая запись — только после ввода **имени целевой базы** (диалог) или `--confirm-target=<имя>`; иначе ничего не пишется.
- Цель, совпадающая с рабочей БД приложения, отклоняется: по настройкам (хост с учётом `localhost`/`127.0.0.1`/`::1`,
  порт, имя базы; приложение на unix-сокете + цель на loopback) и по самим серверам (`@@server_uuid` + `database()`,
  для MariaDB — `@@hostname:@@port` + `database()`). Сравнение **fail-closed**: если сравнить не удалось (БД приложения
  недоступна, запрос упал) — перенос запрещён. Исключения для «другого драйвера» нет: приложение — только MySQL (ADR 0011),
  поэтому рядом с целью нужна рабочая БД приложения на MySQL (в CI — отдельная база `app_test` на том же сервере).
  Цель через unix-сокет не поддерживается; `DB_SOCKET` приложения на цель не наследуется.
- **Финальный боевой прогон — без `--confirm-target`**: имя базы вводится в диалоге руками (флаг — для CI и репетиций).

## Где запускать

С машины/контейнера DevOps IT STEP рядом с MySQL (Vercel для этого не годится), из релиза `main`, чей список миграций совпадает с Neon
(preflight сверяет список миграций; Neon мигрирован релизом `legacy/vercel-postgres`). Миграции, появившиеся в `main` после
заморозки, накатываются на MySQL **после** переноса (`php artisan migrate` текущим релизом); перенос запускается релизом без них.
Среди них есть **миграция данных** `Recruiting/Database/Migrations/2026_10_28_100001_mark_sent_offer_touchpoints` (#183):
помечает `touchpoints.meta.kind = offer` у писем уже отправленных офферов, чтобы таймлайн скрывал зарплату. Только
`UPDATE` поля `meta` (JSON разбирается в PHP, без JSON-функций SQL), порциями по 200, повторный запуск ничего не меняет;
`down()` пустой намеренно (снятие пометки снова открыло бы зарплаты). Перенос копирует `meta` как есть, поэтому порядок
«перенос → migrate» безопасен.
Команде нужен `pdo_pgsql` с libpq ≥ 14 (SNI для Neon). Окружение: `APP_KEY` — **тот же, что у прода** (Vercel env), `APP_ENV=production`,
`TRANSFER_SOURCE_URL`, `TRANSFER_TARGET_URL`. Секреты — через env/секрет-хранилище, не в истории shell, не во временных
файлах. Память: `php -d memory_limit=1G artisan …` (таблицы с вложениями читаются порциями по 4 строки).

## Порядок переключения

1. **Подготовка (за день, прод работает).** DevOps создают MySQL 8.4 (`utf8mb4`, `max_allowed_packet` ≥ 64M) и
   накатывают схему из релиза прода. Строка подключения — только из секрет-хранилища в переменные окружения процесса,
   не в командной строке и не в истории shell, например:
   ```bash
   set +o history                       # или запуск из CI/оркестратора с секретом в env
   export DB_CONNECTION=mysql
   export DB_URL="$(vault kv get -field=url secret/sinhrm/mysql)"   # любой менеджер секретов DevOps
   set -o history
   php artisan migrate --force
   ```
2. **Репетиция без простоя.** `db:transfer-to-mysql --production --preflight`. FAIL → исправить данные в источнике
   (коллизию — переименовать/слить записи по id из отчёта; даты за 2038 — миграцией в `dateTime`) и повторить до OK.
   Затем пробный перенос `--production --truncate-target` и `--verify`: замер времени = окно простоя.
3. **Заморозка записи** (на Vercel `php artisan down` не работает — заморозка делается на уровне БД):
   - в Neon на окно отозвать у роли приложения право записи
     (`REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON ALL TABLES IN SCHEMA public FROM <роль приложения>`) — чтение и вход
     продолжают работать, любая запись получает ошибку;
   - выключить workflow `cron.yml` (вызов `POST /api/ops/jobs/run`) и входящие вебхуки интеграций;
   - **оператор сам проверяет заморозку**: дважды с интервалом 5 минут `db:transfer-to-mysql --production --verify`
     против предыдущей копии или `count(*)` по крупным таблицам — числа не меняются; пробная правка в UI даёт ошибку.
   Снять бэкап Neon ([backup-restore.md](backup-restore.md)).
4. **Финальный перенос:** `php -d memory_limit=1G artisan db:transfer-to-mysql --production --truncate-target`
   — без `--confirm-target`, имя базы вводится в диалоге. Ждём `ИТОГ: OK`.
5. **Сверка:** `db:transfer-to-mysql --production --verify` — ещё раз `ИТОГ: OK`. Любой FAIL → откат (ниже), не «чинить на месте».
6. **Переключение:** в окружении приложения `DB_CONNECTION=mysql`, `DB_URL=mysql://…` (тот же `APP_KEY`), деплой,
   `/api/health` — OK, вход, чтение документа с вложением, интеграция с секретом (Google/AI) работает.
7. **Снять заморозку.** Neon не удалять: он остаётся точкой отката (только чтение) минимум 2 недели.

## Откат на Neon

- **До снятия заморозки** (новых записей в MySQL нет): выкатить на Vercel релиз ветки `legacy/vercel-postgres` (`main` PostgreSQL не
  поддерживает) с `DB_CONNECTION=pgsql`, `DB_URL=<neon>`, вернуть
  роли приложения в Neon права записи (`GRANT INSERT, UPDATE, DELETE, TRUNCATE …`), включить `cron.yml`, снять заморозку. Данные Neon не менялись — откат без потерь.
- **После снятия заморозки** в MySQL появились новые записи: снова заморозка, перенос новых строк обратно в Neon
  (репетиция одного append-only случая была в снятом `backup-restore.yml`; скрипт `scripts/mysql-transfer-proof.php replay-candidate` есть только в релизе `legacy/vercel-postgres` и запускается оттуда;
  правки и удаления после переключения переносятся вручную по журналу аудита), затем `setval` последовательностей и
  переключение `DB_URL`. Поэтому решение об откате принимается в окне, до снятия заморозки.
- MySQL после отката не удалять до разбора причины.

## Проверка

CI: `MySQL data transfer` — PostgreSQL 17 + MySQL 8.4; источник мигрирует и наполняет демо-данными (`db:seed` + `DemoDataService`)
замороженный релиз `legacy/vercel-postgres` (как боевой Neon), цель и команда — текущий код, приложение — отдельная БД MySQL; feature-тест
`MysqlDataTransferTest` (коллизии `a@x/A@x` и `jose/josé` блокируют перенос без печати значений, `Йосип/Иосип` — не коллизия на MySQL 8.4; повторный запуск и
возобновление после «сбоя»; порча вложения/ячейки/FK/`AUTO_INCREMENT` → `ИТОГ: FAIL`; чужой `APP_KEY` → стоп; без
подтверждения и без `--production` → отказ; приложение на соседней БД MySQL сравнивается и разрешено, не-MySQL соединение → fail-closed), затем те же шаги через CLI. Unit: `tests/Unit/Core/Transfer/*`.

## Устройство инструмента

- `TransferToMysqlCommand` — оркестрация: `--preflight` (без записи), `--verify` (без записи), по умолчанию preflight → подтверждение именем целевой БД (`--confirm-target=<имя>` без диалога) → перенос (`--truncate-target` — очистить цель) → сверка. `--production` обязателен при `APP_ENV=production` или не локальных хостах. Подключения — только env `TRANSFER_SOURCE_URL`/`TRANSFER_TARGET_URL` (конфиг `db_transfer.*` — `Core/Transfer/config.php`, подключает `TransferServiceProvider`), цель собирается из настроек соединения `mysql` (тот же `sql_mode`, `+00:00`).
- `TransferDatabases` (подключения, без печати URL; база соединения-источника PostgreSQL — константа `SOURCE_BASE`, в `config/database.php` её нет), `SchemaInspector` (каталоги; схема цели — эталон), `Preflight` (коллизии уникальных значений через `CollationKeys` → `MysqlCollationKeys` = `WEIGHT_STRING` на цели, `CollisionFinder`; 2038; длины; JSON; `max_allowed_packet`; `KeyCheck` — расшифровка `integration_secrets` ключом окружения), `DataCopier` (порции по PK, пропуск уже перенесённых id, `FOREIGN_KEY_CHECKS=0` на сессию, `AUTO_INCREMENT = max+1`), `Reconciler` (строки, XOR-суммы sha256 по колонкам, SHA-256 вложений, сироты FK, `AUTO_INCREMENT`), `ValueCanonicalizer` (bool/JSON/даты/decimal к общему виду), `LaunchGuard`, `SafeError` (ошибки без значений и секретов), `TransferReport` (только имена и счётчики).
- Ревью безопасности (PR #175): цель ≠ рабочая БД — по настройкам с нормализацией loopback-алиасов и сокета и по `@@server_uuid` (MariaDB — `@@hostname:@@port`), fail-closed (`TransferDatabases::appServerVerdict`); без `--truncate-target` строки цели должны быть подмножеством источника (`target_not_empty`); `--verify` включает проверку схемы (`SchemaCheck`: миграции, таблицы и колонки только на одной стороне); текстовые ключи в отчёте коллизий не печатаются; `SafeError` вырезает всё в кавычках; пароли маскируются любой длины.

## Удаление после cutover

Когда прод работает на MySQL IT STEP, сверка `ИТОГ: OK` и окно отката (≥ 2 недели) закрыто — **одним коммитом**:

1. Удалить `backend/app/Modules/Core/Transfer/`, `backend/tests/Unit/Core/Transfer/`, `backend/tests/Feature/Core/Transfer/`,
   `.github/workflows/mysql-data-transfer.yml`.
2. В `CoreServiceProvider::register()` убрать строку `$this->app->register(TransferServiceProvider::class)` и её `use`;
   в `backend/phpstan.neon` убрать `ignoreErrors` для `app/Modules/Core/Transfer/config.php`.
3. В `scripts/mysql-only-guard.mjs` убрать эти пути из `ALLOWED`; `node scripts/mysql-only-guard.mjs` должен остаться зелёным.
4. Этот документ свести к истории (или удалить), строку PROD-49 в [production-backlog.md](../product/production-backlog.md) закрыть;
   ветку `legacy/vercel-postgres` и Neon удаляет владелец отдельно.
