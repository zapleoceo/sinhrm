# ADR 0010 — Целевая БД MySQL 8.4, переходный период с двойной поддержкой PostgreSQL

**Статус:** **заменён [ADR 0011](0011-mysql-only.md)** (2026-10-08): двойной поддержки больше нет, `main` — только MySQL 8.4,
PostgreSQL-прод заморожен на ветке `legacy/vercel-postgres`. Ниже — исторический текст; особенности MySQL и таблица
переносимых конструкций действуют через ADR 0011.

**Исходный статус:** принято владельцем (2026-10-08). Этап 1 — PR «feat/mysql-portability». Задачи — PROD-45…PROD-50 в
[production-backlog.md](../product/production-backlog.md).

**Контекст.** SinHRM написан под PostgreSQL (прод — Vercel + Neon). DevOps IT STEP разворачивают приложения только на
MySQL; без этого перенос на инфраструктуру IT STEP ([itstep-app-handoff.md](../guides/itstep-app-handoff.md)) невозможен.
Прод нельзя остановить на время переписывания: до переезда он остаётся на Neon.

**Решение.**
1. Целевая СУБД — **MySQL 8.4 LTS**, InnoDB, кодировка `utf8mb4`, collation `utf8mb4_0900_ai_ci`, строгий `sql_mode`
   (Laravel `strict`: `ONLY_FULL_GROUP_BY, STRICT_TRANS_TABLES, NO_ZERO_IN_DATE, NO_ZERO_DATE, ERROR_FOR_DIVISION_BY_ZERO,
   NO_ENGINE_SUBSTITUTION`), сессия в UTC (`timezone = +00:00`, как `config/app.php`). Всё зафиксировано в соединении
   `mysql` в `backend/config/database.php`; переменные — `DB_CONNECTION=mysql` и `DB_URL=mysql://…` (или `DB_HOST/DB_PORT/…`).
2. **Двойная поддержка на переходный период.** Один и тот же код работает на PostgreSQL и MySQL. CI: обязательный
   job `tests` (PostgreSQL 17) не меняется; новый job `tests-mysql` (MySQL 8.4, полный phpunit) — **не обязательный и не
   входит в агрегатор `backend`**, пока не будет стабильно зелёным. Neon-логика (`NeonConnectionConfig`) включается только
   при `DB_CONNECTION=pgsql`.
3. После переезда (данные перенесены, откат отрепетирован) поддержка PostgreSQL убирается **отдельной задачей** (PROD-49),
   `tests-mysql` становится обязательным.

## Правила переносимого SQL (для нового кода — обязательны)

Запрещено в коде приложения (ревью — finding): `ILIKE`, `NULLS FIRST/LAST`, операторы `->>`, `->`, `@>`, `?` по JSON в
сыром SQL, `::type`, `ON CONFLICT`, `RETURNING`, `DISTINCT ON`, `FILTER (WHERE …)`, `CAST(… AS VARCHAR|TEXT|INTEGER)`,
`date_trunc`, `to_char`, `string_agg`, `generate_series`, частичные и выражные индексы, `CREATE INDEX IF NOT EXISTS`.

Вместо них:

| Нужно | Переносимый способ |
|---|---|
| NULL в конце/начале | `Core\Support\Database\Sql::orderByNullsLast/First($q, $expr, $dir)` (PostgreSQL/SQLite — родной `NULLS LAST`, он работает и с алиасом select; MySQL — пара «`expr is null`, затем `expr dir`»). `$expr` — идентификатор колонки; подзапрос — через `new Expression(...)` |
| регистронезависимое «содержит» | `Sql::whereContainsCi($q, $expr, $needle, asText: false)` (`lower(..) like ? escape '!'`, `Like::PORTABLE`; `asText: true` приводит колонку к строке) |
| текст по JSON-ключу в select/order | `Sql::jsonText($driver, $column, $key)` (JSON null: строка `'null'` на MySQL, SQL NULL на PostgreSQL); в `where` — Laravel `'col->key'`, `whereJsonContains`, `whereJsonLength` |
| приведение к строке | `Sql::castText($driver, $expr)` |
| апсерт / вставка без дублей / id новой строки | `upsert()`, `insertOrIgnore()`, `insertGetId()` Laravel |
| блокировка строки | `lockForUpdate()` внутри `DB::transaction` |

Ветки `DB::getDriverName()` допускаются только в `Sql` и в миграциях; в модулях — нет (определение драйвера для приведения типа — внутри `Sql`, по грамматике запроса). Строковый `$expr` в `Sql` проверяется: допустимы только идентификаторы `col`/`table.col`; остальное отклоняется `InvalidArgumentException`.

## Отличия MySQL, которые учитываем

- **Collation `utf8mb4_0900_ai_ci` нечувствительна к регистру И к диакритике.** Последствия: уникальный индекс считает
  `Anna@x.com` и `anna@x.com` одним значением (для email это желаемо — приложение и так сравнивает email в нижнем регистре);
  но также `é = e` (проверено тестом в CI; для кириллицы `й` ≠ `и` — тест CI показал, что `й` не сворачивается в `и`, остальные `ё`/`ї` не проверялись), и `WHERE code = 'ABC'` найдёт `abc`. Как обходим: непрозрачные идентификаторы
  (Google ID, Gmail ID, spreadsheet ID, внешние ID касаний, URL профилей) хранятся с `utf8mb4_bin` (ветка в миграции);
  коды/ключи справочников — латиница в нижнем регистре, нормализуются в сервисе до записи; новые уникальные ключи по
  человеческим строкам (ФИО, названия) не вводим. Перед импортом данных Neon — preflight на коллизии новых уникальных
  ключей (PROD-47).
- **Известное расхождение: поиск «содержит».** На MySQL (`utf8mb4_0900_ai_ci`) `Sql::whereContainsCi` не различает диакритику латиницы (`é` = `e`), на PostgreSQL — только регистр; кириллическое `й` не сворачивается в `и` ни там, ни там (проверено в CI). На MySQL поиск шире (находит больше); принимаем, не выравниваем. Фиксирует тест `PortableSqlTest::test_contains_diacritics_known_divergence_mysql_is_wider`.
- **DDL не транзакционный.** Упавшая миграция оставляет таблицу частично созданной. Новые миграции — одна таблица/один
  индекс на миграцию, `Schema::hasTable/hasColumn/hasIndex` перед созданием, корректный `down()`.
- **JSON.** `$table->jsonb()` Laravel создаёт `json` на MySQL; MySQL нормализует JSON (порядок ключей, пробелы, дубли
  ключей) — сравнивать как массивы, не как строки. У `JSON/TEXT/BLOB` нет литерального `DEFAULT` — значение по умолчанию
  ставит модель (`$attributes`), миграция на MySQL default не задаёт.
- **Длина индексов.** `utf8mb4` = 4 байта/символ, лимит ключа InnoDB 3072 байта: `unique` на `varchar(255)` допустим,
  на `TEXT` — только с префиксом или через отдельный `varchar`/хеш-колонку.
- **`ONLY_FULL_GROUP_BY`.** Каждый неагрегированный столбец `SELECT/ORDER BY` — в `GROUP BY`.
- **Строки.** Сравнения и `LIKE` по умолчанию регистронезависимы; `LIKE` с пользовательским вводом — только через `Like`
  (`escape '!'` или обратный слеш по умолчанию — одинаково на обоих драйверах).
- **Типы.** `boolean` = `tinyint(1)` (каст `boolean` в модели обязателен); `foreignId` = `bigint unsigned` — внешние ключи
  того же типа; `DECIMAL` округляет при записи, PostgreSQL `numeric` хранит как есть — суммы округлять в сервисе;
  `timestamp` MySQL ограничен 2038 годом — даты за горизонтом хранить в `dateTime`/`date`.
- **Бинарные данные.** `documents_files.content` — base64 в `longText` (до 2 МиБ), на обоих драйверах одинаково; `bytea`
  не используем, для будущих бинарных колонок — `binary()`/`longBlob` через миграцию.
- **Часовой пояс.** Приложение и сессия БД — UTC; пользовательский пояс (`APP_USER_TIMEZONE`) — только на выводе.

**Риски.**
- Первый `migrate` на MySQL — только на пустой базе: DDL не транзакционный, упавшая миграция оставляет частично созданные таблицы, а существующие миграции не идемпотентны (повторный запуск падает).
- `INSERT IGNORE` в `DemoDataService` (`insertOrIgnore`) мягче PostgreSQL `ON CONFLICT DO NOTHING`: на MySQL он глушит и другие ошибки (усечение, нарушение NOT NULL по умолчанию) предупреждениями.
- Ключи `sessions.id` и `cache.key` без бинарной collation: `utf8mb4_0900_ai_ci` считает их регистро- и диакритико-независимыми, возможны редкие коллизии идентификаторов, различающихся только регистром.
- `ORDER BY` по кириллице на MySQL идёт по UCA (`0900`), на Neon — по collation базы: порядок отдельных строк (`ё`, `й`, смешанный регистр) может отличаться.

**Последствия.** Нужна дисциплина ревью (запрет pg-only синтаксиса), второй CI-прогон (~+3 мин, не блокирует merge).
Индекс по `touchpoints (channel, meta->>'thread')` есть только на PostgreSQL — на MySQL это задача производительности
(PROD-50). Перенос данных Neon → MySQL — команда `db:transfer-to-mysql` и runbook [mysql-cutover.md](../guides/mysql-cutover.md) (PROD-47); backup/restore (`mysqldump`) — отдельный этап (PROD-48).

**Альтернативы.** Остаться на PostgreSQL (DevOps IT STEP не поддерживает); разовый переход без двойной поддержки
(остановка прода на время переписывания и отладки); ORM-only без сырого SQL (отчёты и сортировки по подзапросам требуют
SQL — его закрывает `Sql`).
