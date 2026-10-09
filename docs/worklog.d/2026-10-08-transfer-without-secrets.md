---
date: 2026-10-08
area: Core
---
`db:transfer-to-mysql --without-secrets` — тестовый дамп без прод-`APP_KEY`: `integration_secrets` не копируется (на цели пусто), `--verify` ожидает 0 строк, отчёт печатает пропущенные таблицы с числом строк источника; без флага по-прежнему fail-closed, для боевого cutover флаг нельзя — [mysql-cutover.md](guides/mysql-cutover.md#тестовый-дамп-без-секретов---without-secrets)
