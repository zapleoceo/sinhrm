# PROD-09 — Candidate application scope

## Контекст з KB
Rovo SinHRM queries returned HTTP502; KB unavailable. Planned query: candidate multiple applications branch visibility timeline audit. Luna/root confirmed Scope contract: branch/managed vacancy or interview application; ownership opens candidate entity only.

## План
1. One typed SQL visibility helper reused for list eager loading, details, timeline and history application IDs.
2. Actor scope propagated through services/repositories; retain candidate-global audit and unbound touchpoints. Audit CandidateResource callers.
3. Feature role matrix with shared candidate/two branches/routes/touches/audits; application filters must not reveal hidden rows.
4. Recruiting docs/worklog, targeted local checks if available, isolated draft PR/full CI/root review. No release.

## Стан
- Час: 2026-10-05 Asia/Saigon.
- Step: investigation complete, implementation next. Branch fix/candidate-application-scope, base origin/main 3b4ac30; worktree initially clean.
- Evidence: list/show/timeline/history application queries unrestricted. find() loads city/owner/channel only, so store/update/inbox replies omit applications safely.
- Next: implementation and regression matrix.
- Blockers: PHP absent PATH; KB HTTP502 nonblocking. No local server/full suite. SOL writer authorized.

- Implementation: shared typed ApplicationVisibility SQL helper; list eager loading and application filters, show through repository, actor scoped timeline and audit. CandidateResource store/update/inbox omit applications; no application counts on base main. Bulk/import readshape audit completed.
- Tests: real API role matrix two branches/shared candidate, stage/app/global touches/audits, negative hidden vacancy filters, owner-no-branches and unrelated role denial; update response omits applications. PHP unavailable, CI execution pending.
- Compatibility blocker: PR139 candidateScore aggregates hidden applications without Scope; do not mix its code into this source branch. Root must scope score in combined package with shared-candidate high-hidden-score regression before release.
