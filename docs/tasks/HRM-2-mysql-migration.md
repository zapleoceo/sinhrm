# HRM-2 — MySQL migration readiness

## Стан
- Час: 2026-10-07 UTC.
- Worktree / branch / base SHA: `D:/Projects/sinhrm-wt/mysql-migration`, `feat/mysql-migration`, `fbb18bfa1eeb526cc1517846ab765d3a70140dba` (`origin/main`). Head remains at base; changes are uncommitted.
- Текущий шаг: application compatibility and MySQL CI rehearsal. Production stays on PostgreSQL/Neon.
- Сделано / evidence: isolated clean worktree; reviewed repository rules, handoff and backup guide; Vercel UI confirmed `sinhrm-api` and `sinhrm` on Hobby, production API variables include Neon PostgreSQL integration and no visible MySQL variables. Secret values were not opened. Source defaults, Vercel environment and production traffic were not changed.
- Непроверено: CI MySQL job, full test suite, target MySQL version/provider/TLS, data export/import, production restore and rollback, live feature matrix.
- Блокеры: no confirmed target MySQL, no verified backup/restore of current data, no approved cutover. The automatic review rejected changing the default DB to MySQL before these gates, so this change is excluded.
- Следующий шаг: finish compatibility patches and CI; identify target MySQL and version; perform isolated transfer rehearsal with row, relationship, attachment checksum and ciphertext checks while preserving `APP_KEY`; only then plan cutover.

## Verified inventory at base

- Backend: Laravel 13/PHP 8.4 CI, Angular frontend, two Vercel projects. `backend/config/database.php`, `backend/.env.example`, `backend/phpunit.xml` and `backend/vercel.json` select PostgreSQL. `DB_URL` takes priority over `DATABASE_URL`.
- MySQL connection skeleton exists in Laravel config but has no verified service. The Vercel PHP runtime is `vercel-php@0.9.0`; installed `pdo_mysql` and certificate verification still require runtime proof.
- `backend/app/Modules` contains 28 modules. Migrations contain 57 `jsonb` declarations; MySQL JSON behavior and index limits require integration testing. Recruiting's candidate contact migration uses PostgreSQL partial indexes. Channels has a PostgreSQL expression index with no MySQL equivalent yet.
- Raw SQL `NULLS LAST` occurs in Audit, Directory, People, Recruiting and Users sorts. User-input search also relies on SQL `LIKE` escape and database collation. Other SQL uses views, JSON selectors, upserts, transactions and report aggregates.
- Sessions, cache and Laravel queue tables are in the database; application jobs run through `POST /api/ops/jobs/run` from GitHub Actions every 30 minutes. No `ShouldQueue` handler was found in the handoff inventory. Documents use `documents_files.content` as base64 `longText` for files up to 2 MiB; encrypted integration secrets require the same `APP_KEY`.
- CI, optional night-window CI and synthetic backup proof use PostgreSQL 17. The existing backup proof verifies only PostgreSQL dump/restore and does not establish a MySQL transfer or production RTO.

## Functional acceptance matrix

All rows are pending MySQL integration execution. Existing PostgreSQL tests are a baseline, not proof of MySQL or live integrations.

| Area | Checks required on MySQL | Live check still needed |
|---|---|---|
| Auth, Users, Directory, module access | Google callback, sessions, role and branch scope, sort/filter, last-superadmin and credential revocation | Browser login and revoked session/token |
| Recruiting, HiringRequests, Channels | vacancy/application transitions, candidate contact dedupe and scope, screening ranking, reports, source imports and webhook dedupe | Approved provider/webhook samples |
| People, Time, TimeOff, Assets | employee lifecycle, termination, schedules, leave, inventory relations and null-last ordering | Role-specific browser workflows |
| Documents, Knowledge, Scripts, Reports | 2 MiB attachment roundtrip and SHA-256, templates/signatures, search, reports and exports | Download/preview and output review |
| Integrations, GoogleWorkspace, MailAgent, Ai | ciphertext roundtrip under original key, connector settings, mail ingestion/send, sheets and AI limits | Provider credentials and approved test delivery |
| Core, Workflows, Audit, Privacy, Pulse, Perform, SafeSpeak, Observability, Assistant, Overview, Desk | health, cron idempotency and all registered jobs, access masking, retention, anonymity boundaries, dashboards and interaction flows | Cron, monitoring and browser checks |
| Data operations | row counts, PK/FK, sequences/auto increments, JSON semantics, timezone, binary attachment checksums, sessions/cache, encrypted values | Backup, isolated restore, cutover and rollback drill |

The matrix must be expanded to per-endpoint evidence before declaring every function verified. Preview mocks or SQLite cannot satisfy the MySQL rows.

## Release gate

No production DB switch, destructive seed/reset, source DB deletion or new service/plan/credentials before target details, backup and isolated restore are verified. Keep source database and `APP_KEY` for rollback. A draft PR, green CI on the final SHA and independent review are required before any authorized deployment.
