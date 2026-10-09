---
date: 2026-10-08
area: Core
---
`db:transfer-to-mysql`: мёртвая таблица прототипа `app_state` на боевом Neon больше не валит preflight — явный список `SchemaCheck::LEGACY_SOURCE_ONLY_TABLES` даёт строку `info` с числом строк, таблица не переносится и не сверяется; появится на цели — `FAIL` как дрейф — [mysql-cutover.md](guides/mysql-cutover.md#допустимые-расхождения-схемы)
