# HRM-2 — развёртывание stage/production на MySQL 8.4

## Стан
- Решение — [ADR 0010](../adr/0010-mysql.md): MySQL 8.4 — единственная СУБД. Требования приложения —
  [itstep-app-handoff.md](../guides/itstep-app-handoff.md), база, учётные записи, миграции, TLS и cron —
  [deploy-mysql.md](../guides/deploy-mysql.md), резервные копии — [backup-restore.md](../guides/backup-restore.md) (PROD-48).
- Сделано: код только для MySQL 8.4 (соединение `mysql`: `utf8mb4`, `utf8mb4_0900_ai_ci`, strict, UTC); JSON-колонки без
  database default, непрозрачные идентификаторы в `utf8mb4_bin`, LONGTEXT для документов и базы знаний; CI job `tests` —
  MySQL 8.4 (2026-10-07: MySQL 8.4.11, 1 537 тестов, 13 818 проверок, run 37673730111).
- Данные стенда тестовые: стенд заполняется синтетикой (`demo-fill.yml`); переносить данные не нужно (решение владельца
  2026-10-09).
- Непроверено (нужна площадка IT STEP): версия/TLS сервера MySQL, учётные записи, живые проверки из матрицы ниже.
- Следующий шаг: DevOps разворачивают stage, затем production по [deploy-mysql.md](../guides/deploy-mysql.md); после
  выкладки — `GET /api/health`, `migrate:status`, вход Google и живые проверки матрицы.

## Functional acceptance matrix

CI job `tests` runs every row's automated part on MySQL 8.4; the «live check» column still needs the deployed stage/production.

| Area | Checks required on MySQL | Live check still needed |
|---|---|---|
| Auth, Users, Directory, module access | Google callback, sessions, role and branch scope, sort/filter, last-superadmin and credential revocation | Browser login and revoked session/token |
| Recruiting, HiringRequests, Channels | vacancy/application transitions, candidate contact dedupe and scope, screening ranking, reports, source imports and webhook dedupe | Approved provider/webhook samples |
| People, Time, TimeOff, Assets | employee lifecycle, termination, schedules, leave, inventory relations and null-last ordering | Role-specific browser workflows |
| Documents, Knowledge, Scripts, Reports | 2 MiB attachment roundtrip and SHA-256, templates/signatures, search, reports and exports | Download/preview and output review |
| Integrations, GoogleWorkspace, MailAgent, Ai | ciphertext roundtrip under original key, connector settings, mail ingestion/send, sheets and AI limits | Provider credentials and approved test delivery |
| Core, Workflows, Audit, Privacy, Pulse, Perform, SafeSpeak, Observability, Assistant, Overview, Desk | health, cron idempotency and all registered jobs, access masking, retention, anonymity boundaries, dashboards and interaction flows | Cron, monitoring and browser checks |
| Data operations | PK/FK, auto increments, JSON semantics, timezone, binary attachment checksums, sessions/cache, encrypted values | Backup and isolated restore drill (PROD-48) |

The matrix must be expanded to per-endpoint evidence before declaring every function verified. Preview mocks or SQLite cannot satisfy the MySQL rows.

### Module inventory for the MySQL test run

The existing `backend/tests/Feature/<Module>` suites cover the modules below. They run on MySQL 8.4 in CI job `tests`. Provider calls are faked in CI and need a separate approved live check.

| Module | Existing feature coverage | Live evidence on stage/production |
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

Перед production: зелёный CI на финальном SHA, независимое ревью, проверенные резервная копия и изолированное
восстановление (PROD-48), постоянный `APP_KEY` сохранён вместе с резервными копиями. Destructive seed/reset
(`/api/ops/migrate?fresh=1`) в production запрещён кодом.
