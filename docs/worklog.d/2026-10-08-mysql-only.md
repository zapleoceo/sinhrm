---
date: 2026-10-08
area: Database
---
Только MySQL 8.4 (HRM-40, PROD-49/PROD-50): `DB_CONNECTION=mysql` по умолчанию, одно соединение, миграции без драйверных веток, `Sql` только для MySQL (API прежний), индекс касаний по `meta.thread` — функциональный индекс MySQL; CI `tests` идёт на MySQL 8.4 — [adr/0010-mysql.md](adr/0010-mysql.md)
