---
date: 2026-10-08
area: Архитектура (бэкенд)
pr: 182
---
Рефакторинг без изменения поведения: общие каркасы отчётов, HTTP-проверок интеграций, шагов воркфлоу и исключений модулей (`BusinessRuleException`); из крупных сервисов (AiService, HiringRequestService, Pulse ResponseService, LeaveRequestService) вынесены отдельные классы; роли и статусы — из enum. Дубли бэкенда 1.94% → 1.37% — [architecture-review-2026-10](guides/architecture-review-2026-10.md)
