# PROD-03 — Workflow local day boundary

## План
1. Trace date-only anchors, dueSteps and calendar/nested consumers.
2. Convert local calendar midnight to UTC only after day offsets; preserve date-only values.
3. Regression tests early local day, daytime, offsets, DST and calendar consumer.
4. Docs/worklog, targeted checks, draft PR; no deploy/merge.

## Контекст з KB
CloudId unavailable in current session; previous Rovo SinHRM and Drive searches had no relevant results (orchestrator). Queries: SinHRM workflows timezone scheduling day boundary. Current repo, UserTime contract and production audit are primary evidence.

## Стан
- Крок: implementation after trace.
- Evidence: base origin/main 3b4ac30; today date stored at UTC midnight is incorrectly treated as an instant by WorkflowStarter.
- Completed: traced dueSteps, calendar executor, nested workflow and probation-day consumer.
- Next: tests/CI and independent review.
- Blockers: PHP unavailable locally; runtime checks require authorised release.
- Timestamp: 2026-10-05 Asia/Saigon.

### Handoff
Implementation complete: local-midnight due dates with offsets before UTC conversion; local-day nested/probation anchors; calendar meeting date preserved.
Added deterministic regressions for early local day, daytime, spring/fall DST, ±1 offsets, UTC setting, input immutability and calendar date.
PHP unavailable in PATH; local runtime suite not executed. Full CI required; no live evidence before release.
Existing persisted step due_at snapshots are not rewritten. Next: draft PR CI and Astra review.
