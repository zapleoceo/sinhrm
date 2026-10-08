# ADR 0011 — Только MySQL 8.4; PostgreSQL не поддерживается

**Статус:** принято владельцем (2026-10-08). Заменяет [ADR 0010](0010-mysql-dual-support.md) (двойная поддержка).
Задачи — PROD-49 (удаление PostgreSQL) и PROD-50 (индекс касаний по `meta.thread`) в
[production-backlog.md](../product/production-backlog.md); Jira HRM-40.

**Контекст.** ADR 0010 ввёл переходный период: один код на PostgreSQL (прод Vercel + Neon) и MySQL 8.4 (целевая
инфраструктура IT STEP), удаление PostgreSQL — после переезда. Владелец решил не ждать переезда: `main` сразу
становится MySQL-only, а боевой Vercel + Neon замораживается на ветке `legacy/vercel-postgres` (коммит `8875ac4e`).
Автовыкладка `main` в Vercel отключается отдельным PR (`ci/freeze-vercel-deploy`) — до его мержа этот ADR не вливается.

**Решение.**
1. Единственная СУБД приложения — **MySQL 8.4 LTS** (InnoDB, `utf8mb4`, `utf8mb4_0900_ai_ci`, строгий `sql_mode`, сессия
   `+00:00`) — параметры соединения `mysql` в `backend/config/database.php` не меняются. `DB_CONNECTION` по умолчанию —
   `mysql` (`config/database.php`, `phpunit.xml`, `.env.example`); соединения `pgsql` в конфиге больше нет.
2. Убрано: `NeonConnectionConfig` и его вызов в `CoreServiceProvider`; ветки `DB::getDriverName()` в миграциях (оставлены
   только MySQL-пути, итоговая схема MySQL прежняя); pgsql/SQLite-ветки `Core\Support\Database\Sql` (API тот же:
   `orderByNullsLast/First`, `whereContainsCi`, `jsonText`, `castText`; драйвер, отличный от `mysql`/`mariadb`, —
   `InvalidArgumentException`).
3. **Единственный допустимый PostgreSQL-код** — команда `db:transfer-to-mysql` (модуль Core, `Services/Transfer/*`) и её
   workflow `mysql-data-transfer.yml`: источник переезда (Neon) читается через PDO pgsql. Базовые параметры соединения
   источника — константа `TransferDatabases::SOURCE_BASE` (не `config/database.php`). Neon требует SNI: команду запускать
   с libpq ≥ 14 (любой современный образ PHP); обход старого libpq Vercel убран вместе с рантаймом Vercel.
   Синтетический источник в CI строит замороженный релиз `legacy/vercel-postgres` (миграции и сидеры PostgreSQL) —
   так же, как устроен боевой Neon; цель и команда — из текущего кода.
4. CI: обязательный job `tests` идёт на MySQL 8.4 (сервис `mysql`, полный PHPUnit + покрытие ≥ 70 %), job `tests-mysql`
   удалён; `api-docs` и `night-window.yml` — тоже на MySQL. Имена обязательных проверок и агрегатор `backend`
   (`needs: [lint, tests, api-docs]`) не менялись.
5. Индекс касаний по треду (PROD-50): вместо PostgreSQL-индекса по выражению `(channel, (meta->>'thread'))` —
   функциональный индекс MySQL 8.4 `touchpoints_channel_thread_index` на
   `(channel, (cast(json_unquote(json_extract(meta, '$."thread"')) as char(255)) collate utf8mb4_bin))`. Оптимизатор
   сопоставляет его с `where('meta->thread', …)` Laravel (проверено `EXPLAIN` в тесте). Миграция правлена на месте
   (та же версия `2026_09_30_100001`): список миграций MySQL совпадает с Neon, preflight переноса это требует.
   Функциональный индекс — скрытая колонка: в `information_schema.columns` её нет, перенос и сверка её не видят.
   Значение `meta.thread` длиннее 255 символов MySQL отклонит при вставке (идентификаторы тредов всех адаптеров короткие).

## Правила SQL (для нового кода — обязательны)

Запрещено (ревью — finding): синтаксис других СУБД (`ILIKE`, `NULLS FIRST/LAST`, `->>` в сыром SQL, `::type`,
`ON CONFLICT`, `RETURNING`, `DISTINCT ON`, `FILTER (WHERE …)`, `date_trunc`, `string_agg`, `generate_series`, частичные
индексы) и ветки `DB::getDriverName()` в модулях и миграциях. Вместо них — Laravel builder и `Core\Support\Database\Sql`
(таблица из ADR 0010 действует, без колонок «PostgreSQL»). Особенности MySQL из ADR 0010 (collation `_ai_ci` и
`utf8mb4_bin` для непрозрачных идентификаторов, нетранзакционный DDL, JSON без литерального `DEFAULT`, длина индексов,
`ONLY_FULL_GROUP_BY`, `TIMESTAMP` до 2038, UTC) остаются в силе.

## Семантика, сверенная при переходе

| Что | PostgreSQL (было) | MySQL 8.4 (стало) | Чем закреплено |
|---|---|---|---|
| NULL в конце/начале | `NULLS LAST/FIRST` | `expr is null asc/desc, expr dir` — тот же порядок | `PortableSqlTest::test_nulls_go_last_or_first_in_both_directions` |
| «Содержит» без регистра | `lower(..) like` — только регистр | то же; collation `_ai_ci` дополнительно не различает латинскую диакритику (`é = e`), `й ≠ и` | `PortableSqlTest::test_contains_ignores_latin_diacritics_but_keeps_cyrillic_short_i` |
| JSON-ключ текстом | `->>`, JSON null → SQL NULL | `json_unquote(json_extract(..))`, JSON null → строка `'null'` | `SqlTest`, `PortableSqlTest::test_json_text_reads_a_key_on_the_current_driver` |
| Уникальные контакты кандидата | частичный индекс `WHERE col IS NOT NULL` | обычный unique: MySQL не считает NULL дублями | `RecruitingMysqlSchemaTest` |
| Непрозрачные id | collation базы (регистрозависимо) | `utf8mb4_bin` | тесты `*MysqlSchemaTest` модулей Auth, GoogleWorkspace, MailAgent, Recruiting |

**Последствия.**
- `main` нельзя выкатить на Vercel + Neon: исправления боевого PostgreSQL-прода до переезда — только в ветке
  `legacy/vercel-postgres`. `backend/vercel.json` в `main` оставлен как есть (`DB_CONNECTION=pgsql`): выкладка заморожена,
  файл не используется до решения DevOps IT STEP о хостинге.
- Переезд данных Neon → MySQL — прежний runbook [mysql-cutover.md](../guides/mysql-cutover.md): preflight требует
  совпадения списка миграций, поэтому новые миграции после заморозки накатываются на MySQL **после** переноса.
- Откат на Neon — переключением на релиз `legacy/vercel-postgres`, не `main`.
- `backup-restore.yml` (доказательство `pg_dump`) переделывается под MySQL отдельно (HRM-38, PR #174); до этого он
  падает на PR, меняющих `backend/**` (не обязательная проверка).

**Альтернативы.** Двойная поддержка до переезда (ADR 0010) — каждый PR платит вторым CI-прогоном и дисциплиной pg/MySQL,
а прод на Neon заморожен и новых фич не получает; оставить `pgsql` в конфиге «на всякий случай» — PostgreSQL-путь без
тестов ломается молча.
