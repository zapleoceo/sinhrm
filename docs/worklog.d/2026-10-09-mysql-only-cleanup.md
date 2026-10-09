---
date: 2026-10-09
area: Core
---
MySQL 8.4 — единственная СУБД без исключений: один ADR [0010](adr/0010-mysql.md), гайд [развёртывания на MySQL 8.4](guides/deploy-mysql.md) (БД, учётные записи, миграции, TLS, cron); CI-страж `scripts/mysql-only-guard.mjs` без allowlist.
Удалён разовый инструмент переноса данных с тестами и workflow (данные стенда тестовые). CI на MySQL 8.4 проверяет миграции с нуля, полный откат и smoke всех GET-эндпоинтов после seed — [deploy.md](guides/deploy.md).
