---
date: 2026-09-29
area: CI
pr: 95
---
Бэкенд-CI разбит на параллельные job `lint` / `tests` / `api-docs` + агрегатор `backend`, Pint `--parallel`, кеши Pint/PHPStan, Vercel CLI закреплён: бэкенд ~7,5 → ~3 мин — [guides/deploy.md](guides/deploy.md)
