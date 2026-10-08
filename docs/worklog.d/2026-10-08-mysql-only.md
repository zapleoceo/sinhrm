---
date: 2026-10-08
area: Database
---
Только MySQL 8.4 (HRM-40, PROD-49/PROD-50): `DB_CONNECTION=mysql` по умолчанию, соединение `pgsql` и `NeonConnectionConfig` удалены, миграции без драйверных веток, `Sql` без PostgreSQL-вариантов (API прежний), индекс касаний по `meta.thread` — функциональный индекс MySQL; CI `tests` идёт на MySQL 8.4 вместо PostgreSQL, `tests-mysql` удалён; PostgreSQL остался только в `db:transfer-to-mysql` (источник в CI строит замороженный релиз `legacy/vercel-postgres`) — [adr/0011-mysql-only.md](adr/0011-mysql-only.md)
