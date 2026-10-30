---
date: 2026-10-13
area: Core
---
Фронт и бэк на одном домене: nginx отдаёт бэкенду только `/api/*` и `/health`, сервер фронтенда больше не проксирует; CSRF-cookie теперь `/api/sanctum/csrf-cookie` — [core.md](modules/core.md)
