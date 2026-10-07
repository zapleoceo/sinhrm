# HRM-2 — MySQL migration readiness

## Стан
- Час: 2026-10-07 UTC.
- Worktree / branch / base SHA: `D:/Projects/sinhrm-wt/mysql-migration`, `feat/mysql-migration`, `fbb18bfa1eeb526cc1517846ab765d3a70140dba` (`origin/main`). Draft PR #169 tracks the pushed head.
- Текущий шаг: application compatibility and MySQL CI rehearsal. Production stays on PostgreSQL/Neon.
- Сделано / evidence: isolated worktree; reviewed repository rules, handoff and backup guide; Vercel UI confirmed `sinhrm-api` and `sinhrm` on Hobby, production API variables include Neon PostgreSQL integration and no visible MySQL variables. Secret values were not opened. Source defaults, Vercel environment and production traffic were not changed. Local docs/tests/worklog policy checks passed. Independent Astra reviewed committed head and the Reports/MySQL 8.4 delta, found and verified the PostgreSQL rollback fix. CI run 37669398014 and synthetic restore run 37669398064 belong to the previously pushed SHA; the latter passed.
- Непроверено: CI MySQL job, full test suite, target MySQL version/provider/TLS, data export/import, production restore and rollback, live feature matrix.
- Блокеры: no confirmed target MySQL, no verified backup/restore of current data, no approved cutover. The automatic review rejected changing the default DB to MySQL before these gates, so this change is excluded.
- Следующий шаг: finish compatibility patches and CI; identify target MySQL and version; perform isolated transfer rehearsal with row, relationship, attachment checksum and ciphertext checks while preserving `APP_KEY`; only then plan cutover.

## Verified inventory at base

- Backend: Laravel 13/PHP 8.4 CI, Angular frontend, two Vercel projects. `backend/config/database.php`, `backend/.env.example`, `backend/phpunit.xml` and `backend/vercel.json` select PostgreSQL. `DB_URL` takes priority over `DATABASE_URL`.
- MySQL connection skeleton exists in Laravel config but has no verified service. MySQL 8.4 LTS is the current development test target, not a verified production version. The Vercel PHP runtime is `vercel-php@0.9.0`; installed `pdo_mysql` and certificate verification still require runtime proof.
- `backend/app/Modules` contains 28 modules. Migrations contain 57 `jsonb` declarations; MySQL JSON behavior and index limits require integration testing. Recruiting's candidate contact migration uses PostgreSQL partial indexes. Channels has a PostgreSQL expression index with no MySQL equivalent yet.
- Raw SQL `NULLS LAST` occurs in Audit, Directory, People, Recruiting and Users sorts. User-input search also relies on SQL `LIKE` escape and database collation. Other SQL uses views, JSON selectors, upserts, transactions and report aggregates.
- Sessions, cache and Laravel queue tables are in the database; application jobs run through `POST /api/ops/jobs/run` from GitHub Actions every 30 minutes. No `ShouldQueue` handler was found in the handoff inventory. Documents use `documents_files.content` as base64 `longText` for files up to 2 MiB; encrypted integration secrets require the same `APP_KEY`.
- CI, optional night-window CI and synthetic backup proof use PostgreSQL 17. The existing backup proof verifies only PostgreSQL dump/restore and does not establish a MySQL transfer or production RTO.
- Before import, preflight target collation for new unique-key collisions (including case/accent folding), MySQL timestamp date range and timezone conversion, JSON null/order semantics, and maximum index lengths. These cannot be inferred from PostgreSQL green tests.

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

### Module inventory for the MySQL test run

The existing `backend/tests/Feature/<Module>` suites cover the modules below. Their presence identifies a test entry point; no row is marked verified until the final MySQL job passes. Provider calls are faked in CI and need a separate approved live check.

| Module | Existing feature coverage | Additional cutover evidence |
|---|---|---|
| Ai | prompts, editor, service | configured broker request and limits |
| Assets | inventory API and routes | assignment/return with real roles |
| Assistant | chat, voice, tools, data minimization | approved provider/browser flow |
| Audit | log, filters and sorting | retention and access masking |
| Auth | Google callback, active role, tokens | real Google login and cookie session |
| Channels | administration, messages, webhook | authorized channel/webhook sample |
| Core | health, role gates, module access, cron, ops | live health and scheduler run |
| Desk | desk API/routes | role-scoped task flow |
| Directory | dictionary CRUD and sorting | branch and city scope |
| Documents | templates, signatures, upload/download | 2 MiB checksum and download |
| GoogleWorkspace | connect/token, Gmail send, meetings, Sheets | authorized provider callback and send |
| HiringRequests | request/approval and hiring acceptance | approval-to-recruiter flow |
| Integrations | integration settings, employee directory, messenger checks | ciphertext under retained `APP_KEY` and provider check |
| Knowledge | article API/routes | case-insensitive search |
| MailAgent | admin, AI classification, sync | approved mailbox ingestion |
| Observability | error log | retention and alert visibility |
| Overview | dashboard API/access/routes | role-scoped dashboard data |
| People | employee changes, hire, termination, sorting | lifecycle and handover |
| Perform | feedback, reviews, objectives, plans, KPIs, 1:1 | access to review cycle |
| Privacy | personal-data API | data subject request and masking |
| Pulse | surveys, mood, anonymity, scheduled exit survey | anonymity and cron boundary |
| Recruiting | candidates, vacancies, pipeline, screening, offers, career site, inbox, reports | scoped end-to-end application and screening |
| Reports | catalog, builder, saved reports, CSV | filters and export download |
| SafeSpeak | confidential API/routes | anonymity boundary |
| Scripts | scripts, evaluations, templates, follow-ups, task scheduler | scheduled follow-up and reports |
| Time | time API/routes | schedule and timesheet |
| TimeOff | leave, accrual, approvals, calendar, concurrency | leave approval, mail/calendar, cron |
| Users | roles, administration, sorting, credential revocation | revoked sessions and tokens |
| Workflows | templates, runs, termination handover | scheduled tick and retry |

## Release gate

No production DB switch, destructive seed/reset, source DB deletion or new service/plan/credentials before target details, backup and isolated restore are verified. Keep source database and `APP_KEY` for rollback. A draft PR, green CI on the final SHA and independent review are required before any authorized deployment.

### CI checkpoint, 2026-10-07

Earlier run 37670048366 reached MySQL 8.4.11 but failed because Laravel emitted `JSON DEFAULT '{}'` for `integrations.settings`; its other failures cascaded from migration setup. The fix and compatibility regressions passed in final-head CI run 37673730111: MySQL 8.4.11 ran 1,537 passing tests and 13,818 assertions; all workflow jobs succeeded. This proves a fresh MySQL schema and synthetic application behavior, not data transfer or production cutover. Production remains on PostgreSQL.

### Synthetic cross-database transfer rehearsal

The CI-only `synthetic-mysql-transfer` job prepares disposable PostgreSQL 17 and MySQL 8.4 databases and one synthetic `APP_KEY`. It migrates both schemas, seeds a linked recruiting/people/documents/knowledge/integrations graph into PostgreSQL, copies missing rows in foreign-key order without clearing the source or target, and checks table counts and orphaned foreign keys. The fixture verifies stable IDs, JSON/Unicode, long Markdown and HTML, timestamps, an exact 2 MiB attachment and SHA-256, and byte-identical ciphertext that still decrypts under the same key. A candidate written after the simulated cutover is replayed into the retained source, including sequence repair. The rehearsal is limited to synthetic rows and one append-only rollback write; production updates/deletes, collation collisions, concurrent writes, and full reconciliation remain release gates. The job has not passed until its final SHA CI result is recorded.
