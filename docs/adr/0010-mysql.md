# ADR 0010 — MySQL 8.4 как единственная СУБД

**Статус:** принято владельцем (2026-10-08). Задачи — PROD-45…PROD-50 в
[production-backlog.md](../product/production-backlog.md).

**Контекст.** DevOps IT STEP разворачивают приложения на MySQL; приложение размещается на их инфраструктуре
([itstep-app-handoff.md](../guides/itstep-app-handoff.md), развёртывание — [deploy-mysql.md](../guides/deploy-mysql.md)).
Одна СУБД во всех средах (CI, стенд, прод) — одна схема, один набор тестов, без драйверных веток.

**Решение.**
1. Единственная СУБД — **MySQL 8.4 LTS**, InnoDB, кодировка `utf8mb4`, collation `utf8mb4_0900_ai_ci`, строгий `sql_mode`
   (Laravel `strict`: `ONLY_FULL_GROUP_BY, STRICT_TRANS_TABLES, NO_ZERO_IN_DATE, NO_ZERO_DATE, ERROR_FOR_DIVISION_BY_ZERO,
   NO_ENGINE_SUBSTITUTION`), сессия в UTC (`timezone = +00:00`, как `config/app.php`). Всё зафиксировано в соединении
   `mysql` в `backend/config/database.php`; `DB_CONNECTION=mysql` по умолчанию (`config/database.php`, `phpunit.xml`,
   `.env.example`), подключение — `DB_URL=mysql://…` (или `DB_HOST/DB_PORT/…`), TLS — `MYSQL_ATTR_SSL_CA`.
2. Других соединений в конфиге нет; веток `DB::getDriverName()` в модулях и миграциях нет.
3. SQL, который Laravel builder не выражает, — только через `Core\Support\Database\Sql` (таблица ниже). Драйвер, отличный
   от `mysql`/`mariadb`, — `InvalidArgumentException`.
4. CI: обязательный job `tests` идёт на MySQL 8.4 (сервис `mysql`, полный PHPUnit + покрытие ≥ 70 %); `api-docs` и
   `night-window.yml` — тоже на MySQL. Страж `scripts/mysql-only-guard.mjs` (job `lint`) не пропускает в репозиторий
   синтаксис и упоминания других СУБД.
5. Индекс касаний по треду (PROD-50) — функциональный индекс `touchpoints_channel_thread_index` на
   `(channel, (cast(json_unquote(json_extract(meta, '$."thread"')) as char(255)) collate utf8mb4_bin))`. Оптимизатор
   сопоставляет его с `where('meta->thread', …)` Laravel (проверено `EXPLAIN` в тесте). Функциональный индекс — скрытая
   колонка: в `information_schema.columns` её нет. Значение `meta.thread` длиннее 255 символов MySQL отклонит при вставке
   (идентификаторы тредов всех адаптеров короткие).

## Правила SQL (для нового кода — обязательны)

Запрещено (ревью — finding, часть ловит страж): синтаксис, которого нет в MySQL (`NULLS FIRST/LAST`, `::type`,
`ON CONFLICT`, `RETURNING`, `DISTINCT ON`, `FILTER (WHERE …)`, `date_trunc`, `string_agg`, `generate_series`, частичные
индексы), операторы `->>`/`->` по JSON в сыром SQL, `CAST(… AS VARCHAR|TEXT|INTEGER)` и ветки `DB::getDriverName()`.

| Нужно | Способ |
|---|---|
| NULL в конце/начале | `Sql::orderByNullsLast/First($q, $expr, $dir)` — пара «`expr is null`, затем `expr dir`». `$expr` — идентификатор колонки; подзапрос — через `new Expression(...)` |
| регистронезависимое «содержит» | `Sql::whereContainsCi($q, $expr, $needle, asText: false)` (`lower(..) like ? escape '!'`, `Like::PORTABLE`; `asText: true` приводит колонку к строке) |
| текст по JSON-ключу в select/order | `Sql::jsonText($driver, $column, $key)` = `json_unquote(json_extract(..))` (JSON null → строка `'null'`); в `where` — Laravel `'col->key'`, `whereJsonContains`, `whereJsonLength` |
| приведение к строке | `Sql::castText($driver, $expr)` = `cast(.. as char(n))` |
| апсерт / вставка без дублей / id новой строки | `upsert()`, `insertOrIgnore()`, `insertGetId()` Laravel |
| блокировка строки | `lockForUpdate()` внутри `DB::transaction` |

Строковый `$expr` в `Sql` проверяется: допустимы только идентификаторы `col`/`table.col`; остальное отклоняется
`InvalidArgumentException`.

## Особенности MySQL, которые учитываем

- **Collation `utf8mb4_0900_ai_ci` нечувствительна к регистру и к диакритике.** Уникальный индекс считает `Anna@x.com` и
  `anna@x.com` одним значением (для email это желаемо — приложение и так сравнивает email в нижнем регистре); `é = e`,
  а кириллическое `й ≠ и` (проверено тестом `PortableSqlTest`). `WHERE code = 'ABC'` найдёт `abc`. Поэтому непрозрачные
  идентификаторы (Google ID, Gmail ID, spreadsheet ID, внешние ID касаний, URL профилей) хранятся с `utf8mb4_bin`
  (тесты `*MysqlSchemaTest` модулей Auth, GoogleWorkspace, MailAgent, Recruiting); коды/ключи справочников — латиница в
  нижнем регистре, нормализуются в сервисе до записи; уникальные ключи по человеческим строкам (ФИО, названия) не вводим.
  Поиск «содержит» поэтому не различает латинскую диакритику — принимаем (`PortableSqlTest`).
- **Ключи `sessions.id` и `cache.key`** — с collation базы: теоретически возможны коллизии идентификаторов, различающихся
  только регистром; идентификаторы Laravel случайные, риск принят.
- **DDL не транзакционный.** Упавшая миграция оставляет таблицу частично созданной. Новые миграции — одна таблица/один
  индекс на миграцию, `Schema::hasTable/hasColumn/hasIndex` перед созданием, корректный `down()`. Первый `migrate` — на
  пустой базе.
- **`INSERT IGNORE`.** `insertOrIgnore()` на MySQL — `INSERT IGNORE`: кроме дублей уникального ключа он превращает в
  предупреждения и другие ошибки (усечение, NOT NULL по умолчанию). Использовать только там, где строка заведомо валидна
  (связки ролей, демо-данные, идемпотентные отметки), а не для пользовательского ввода. `upsert()` на MySQL игнорирует
  `$uniqueBy` и реагирует на любой уникальный индекс — у таблицы под `upsert()` ровно один уникальный ключ.
- **JSON.** Колонки `json()`; MySQL нормализует JSON (порядок ключей, пробелы, дубли ключей) — сравнивать как массивы, не
  как строки. У `JSON/TEXT/BLOB` нет литерального `DEFAULT` — значение по умолчанию ставит модель (`$attributes`).
  JSON null по ключу читается строкой `'null'` (`Sql::jsonText`).
- **`TIMESTAMP` ограничен 2038 годом** (`2038-01-19 03:14:07` UTC). Даты за горизонтом (сроки действия, плановые даты)
  хранить в `dateTime`/`date`.
- **`max_allowed_packet`.** Документы до 2 МиБ хранятся base64 в `longText` (`documents_files.content`, ≈ 2,7 МиБ на
  строку): `max_allowed_packet` сервера и клиентов (`mysql`, `mysqldump`) не уменьшать ниже 16 МиБ (по умолчанию в
  MySQL 8.4 — 64 МиБ у сервера, 24 МиБ у `mysqldump`).
  Для будущих бинарных колонок — `binary()`/`longBlob` через миграцию.
- **Длина индексов.** `utf8mb4` = 4 байта/символ, лимит ключа InnoDB 3072 байта: `unique` на `varchar(255)` допустим, на
  `TEXT` — только с префиксом или через отдельный `varchar`/хеш-колонку.
- **`ONLY_FULL_GROUP_BY`.** Каждый неагрегированный столбец `SELECT/ORDER BY` — в `GROUP BY`.
- **Строки.** Сравнения и `LIKE` по умолчанию регистронезависимы; `LIKE` с пользовательским вводом — только через `Like`
  (`escape '!'`). `ORDER BY` по кириллице идёт по UCA (`0900`).
- **Типы.** `boolean` = `tinyint(1)` (каст `boolean` в модели обязателен); `foreignId` = `bigint unsigned` — внешние ключи
  того же типа; `DECIMAL` округляет при записи — суммы округлять в сервисе.
- **Часовой пояс.** Приложение и сессия БД — UTC; пользовательский пояс (`APP_USER_TIMEZONE`) — только на выводе.

**Последствия.** Одна схема и один CI-прогон; дисциплина ревью по правилам SQL выше; резервное копирование —
`mysqldump`/`mysql` ([backup-restore.md](../guides/backup-restore.md)).

**Альтернативы.** Поддержка нескольких СУБД — второй CI-прогон и драйверные ветки, которые без тестов ломаются молча;
ORM-only без сырого SQL — отчёты и сортировки по подзапросам требуют SQL, его закрывает `Sql`.
