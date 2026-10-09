---
date: 2026-10-08
area: Database
pr: 170
---
MySQL 8.4 — целевая БД (ADR 0010): двойная поддержка PostgreSQL/MySQL, `Core\Support\Database\Sql` вместо `NULLS LAST`/`ILIKE`/драйверных веток, соединение `mysql` (utf8mb4, strict, UTC), необязательный CI job `tests-mysql` — [adr/0010-mysql-dual-support.md](adr/0010-mysql-dual-support.md)
Совместимость с MySQL: JSON-колонки без database default, непрозрачные идентификаторы с binary collation, LONGTEXT для документов и базы знаний, сортировки, уникальность контактов кандидатов и фильтр отчётов; синтетический перенос PostgreSQL→MySQL (115 таблиц, сверка по ключам) прошёл в CI — [план миграции](tasks/HRM-2-mysql-migration.md).
Рабочая база остаётся на PostgreSQL до проверенного переноса данных и отката; боевой перенос требует доступов к целевой БД, проверенной копии источника и плана сверки.
