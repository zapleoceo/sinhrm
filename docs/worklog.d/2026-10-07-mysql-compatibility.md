---
date: 2026-10-07
area: Database
---

MySQL 8.4 CI exposed an invalid JSON column default. The compatibility branch now omits that database default on MySQL, preserves the Eloquent default, gives opaque identifiers binary collation, expands accepted document and knowledge text to LONGTEXT, and adds synthetic case and Unicode roundtrip tests. The final MySQL CI run remains the proof gate.

Подготовлена совместимость сортировок, уникальности контактов кандидатов и фильтра отчётов с MySQL; добавлен отдельный MySQL CI job и проверка 2 МиБ вложения. Рабочая база остаётся на PostgreSQL до проверенного переноса данных и отката — [план миграции](../tasks/HRM-2-mysql-migration.md).
