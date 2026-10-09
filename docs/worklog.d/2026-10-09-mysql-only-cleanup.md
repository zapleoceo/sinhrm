---
date: 2026-10-09
area: Core
---
MySQL 8.4 — единственная СУБД без исключений: один ADR [0010](adr/0010-mysql.md), гайд [развёртывания на MySQL 8.4](guides/deploy-mysql.md) (БД, учётные записи, миграции, TLS, cron); CI-страж `scripts/mysql-only-guard.mjs` без allowlist.
Удалены разовый инструмент переноса данных с его тестами и workflow (данные стенда тестовые) и отключённый workflow выкладки `deploy.yml` — выкладку делает pipeline IT STEP ([deploy.md](guides/deploy.md)).
