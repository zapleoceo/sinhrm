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
- Step: implementation ready for combined regression; branch fix/candidate-application-scope, base origin/main3b4ac30, tested implementation head b27f6f428074180805055811aecdd75cb7565e41.
- Evidence: list/show/timeline/history application queries unrestricted. find() loads city/owner/channel only, so store/update/inbox replies omit applications safely.
- Next: final state-delta CI/review, then combined PR139 compatibility before merge. No release performed.
- Blockers: PHP absent PATH; KB HTTP502 nonblocking. Full suite passed in CI, not locally. Latest owner instruction selected Luna execution and independent Astra review.

- Implementation: shared typed ApplicationVisibility SQL helper; list eager loading and application filters, show through repository, actor scoped timeline and audit. CandidateResource store/update/inbox omit applications; no application counts on base main. Bulk/import readshape audit completed.
- Tests: real API role matrix two branches/shared candidate, stage/app/global touches/audits, negative hidden vacancy filters, owner-no-branches and unrelated role denial; update response omits applications. PHP unavailable, CI execution pending.
- Compatibility: PR139 owning score-scope correction is prepared at cc7b9ff,10 checks SUCCESS and Astra PASS. Do not mix its feature into this branch; shared helper compatibility still needs combined verification.

- Screening boundary: GET latest screenings is scoped before polling/serialization; manual POST requires visible application and existing candidate edit right. Prompt materials use the selected application and global candidate touches. Stable prompt text/version/provider/cache configuration unchanged; no live AI/cache evidence. Role matrix covers private screening rationale and hidden start denial.
- Validation: `git diff --check` and `node scripts/worklog-build.mjs --print` passed. PHP is unavailable locally, so targeted PHPUnit was not run; the full backend suite is delegated to CI. Earlier CI on commit `5c2d8c7` passed all jobs, including backend; it does not include this screening boundary.
- CI `37280378724`: all jobs except backend and dependent aggregate passed; backend PHPUnit reported `CandidateApplicationScopeTest` line 54 because the synthetic global email omitted `direction=in`. Added that fixture value without weakening the assertion. Also adding a hidden pending screening/request regression asserting no provider poll and no status change.
- Final implementation proof: b27f6f4 run37281215729 has10 checks SUCCESS,1362 backend tests/12572 assertions, independent Astra local/GitHub PASS. Fixture direction and hidden-PENDING no-poll/status regressions are verified. This later evidence supersedes the earlier pending/failing runs above; production provider calls were not performed.
