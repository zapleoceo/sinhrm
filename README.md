# SinHRM

Recruiting & HR platform — successor of Sintegrum (test, non-commercial project).

- `frontend/` — Angular SPA (Node 24; сборка `npm run build`)
- `backend/` — Laravel 13 API (PHP 8.4, MySQL 8.4 — единственная поддерживаемая СУБД, [ADR 0010](docs/adr/0010-mysql.md))
- `extension/` — Chrome extension «SinHRM Clipper» (MV3): adds a candidate from the open LinkedIn / Work.ua / Djinni / DOU profile page on click (`docs/modules/extension.md`)
- `docs/` — project documentation (start with `docs/README.md`)

Автовыкладка на Vercel заморожена с 2026-10-08 (`docs/guides/deploy.md`, раздел «Заморозка Vercel»); боевой сайт `https://sinhrm.vercel.app` работает на отдельной ветке релиза; развёртывание `main` — инфраструктура IT STEP на MySQL 8.4 (`docs/guides/deploy-mysql.md`).

All rights reserved. Source is public for transparency only.
