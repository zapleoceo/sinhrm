# PROD-18 — Pilot module boundary

## План
1. Real HTTP due workflow → disable Workflows → ops skip + unchanged workflow data/tasks → re-enable → execute once on next tick.
2. Verify unrelated job continues consistently without mocked ScheduledJob.
3. Operator pilot protocol: chosen scope, module/role matrix, separate AI/provider controls, real login/browser/API/mobile/keyboard evidence and limitations.
4. Docs/worklog + full CI draft PR; no production config/deploy actions.

## Контекст з KB
Vera query SinHRM pilot module access disabled workflows acceptance returned an older architecture summary (2026-09-25), no pilot/module decision; second result unrelated.
Prior Rovo/Drive lookup had no relevant project data; cloudId unavailable. Current ModuleAccess/OpsJobsController/ModuleAccessTest and frontend/e2e are primary evidence.

## Стан
- Step: traced existing gate and gap; implementation.
- Base: origin/main 3b4ac30.
- Existing proof disables Scripts and checks Workflows runs; missing real due Workflows-disabled data preservation proof.
- Next: CI and Astra review; live pilot needs owner account and selected module/role scope.
- Blockers: PHP unavailable locally; live steps not executed.
- Timestamp: 2026-10-05 Asia/Saigon.

## Handoff
Focused real HTTP/DB module boundary test added; no mocked ScheduledJob. Same safe frozen daytime on old/new scheduler.
Disabled tick preserves run/step attributes and workflow task count; re-enable creates linked pending-human task; repeated tick creates none.
Live protocol documents module/role scope, separate providers, actual browser/API/mobile/keyboard, E2E fixtures and desktop-profile skips.
Local PHP absent; git whitespace check and docs/tests guards only; full CI next. No production config actions or deploy.
