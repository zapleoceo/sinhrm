---
date: 2026-10-08
area: Архитектура (бэкенд)
pr: 181
---
Рефакторинг без изменения поведения: запросы к БД из контроллеров и сервисов перенесены в репозитории модулей (Observability, Privacy, Recruiting, People, Auth); другие модули зависят от контрактов (`PeopleAccess`, `EmployeeLookup`, `TaskScheduler`, `AiGateway`, `RecruitingAccess` и др.), а не от чужих сервисов. Нарушений границ модулей 255 → 188 — [architecture-review-2026-10](guides/architecture-review-2026-10.md)
