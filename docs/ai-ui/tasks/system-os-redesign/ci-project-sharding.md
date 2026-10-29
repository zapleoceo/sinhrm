# UI parity four-project sharding

## Scope

Reduce the wall time and improve recoverability of the Browser OS `ui-parity` CI run without changing test assertions, inventory snapshots, or axe baselines. Each of the existing four Playwright projects must run exactly once with two workers. Keep a stable aggregate status check that rejects any non-success shard result.

## Plan

1. Build the production frontend once, then let four independent project jobs execute the existing E2E suite selection with two workers and `fail-fast: false`.
2. Give each project a distinct HTML report, screenshot, and inventory evidence directory/artifact. Upload those artifacts with `always()` so failure or cancellation still retains whatever files exist.
3. Persist observed inventory JSON to `.out/inventories/<project>/<page-state>.json` before snapshot assertions, while retaining the existing Playwright attachments and equality gate. Do not write or merge `axe-baseline.json` during ordinary checks.
4. Add an offline Playwright discovery test that compares test identities for the unfiltered run with the union of all four project selections and rejects missing, duplicated, or extra IDs.
5. Update UI parity guidance and this task state/worklog, then run targeted offline validation only. Browser execution and build remain in CI.

## State

2026-10-30 03:01 Asia/Saigon. Worktree `D:/Projects/HRM/worktrees/browser-os-ci-projects`, branch `feat/browser-os-ci-projects`, verified base/source `6a41a5072d526776f1365e89a2860ee8cbde3771`; clean before edits. User-reported source run `40942548984` hit the 15-minute job timeout and was cancelled; screenshots artifact (102 MB) survived, HTML report was missing because upload used `failure()` and inventory JSON was only attached to test results. No local build/browser run. Next: implement CI sharding and recoverable evidence, run discovery/lint/static workflow checks, freeze for SOL review and candidate port.

2026-10-30 03:06 Asia/Saigon. Implemented one shared production build and four `fail-fast: false` project jobs (desktop-light/dark, mobile-light/dark), each invoking only its own project with two workers. Added the stable `ui-parity` aggregator; it requires both the build and matrix result to equal `success`, including treating cancelled/skipped jobs as failure. Each shard writes uniquely named HTML report, screenshot and inventory artifacts with `always()` upload; report/screens/inventories upload ignores absent files so cancellation does not replace a successful shard status. Actual inventories now persist under `.out/inventories/<project>/` before baseline comparison/expectation, while existing attachments and snapshot/axe comparisons remain intact. The axe baseline update path remains opt-in via `E2E_UPDATE` and was not changed.

- Offline Playwright discovery test passed: 532 project-qualified identities in the unfiltered listing equal the disjoint union of four selections (133 per selection); no duplicate/missing IDs. `npm run e2e:lint` passed, including 5 inventory parser tests, discovery, ESLint and E2E TypeScript. YAML parsed and a structural check confirmed the four project names, `fail-fast: false`, and aggregate dependencies. Docs links: 229 files, 0 dead links; worklog validation: 29/29; `git diff --check` passed.
- `npm ci` succeeded from the committed lockfile (npm reported one moderate audit advisory; dependencies were not changed). No production build, Playwright browser run, screenshots or runtime durations were generated locally. The previous source run and its 102 MB screenshots remain external CI evidence; this branch has no new CI result yet.
- **Next:** freeze for exact SOL review and root's integration candidate port; rerun production build and all four browser shards in CI. No commit, push, merge or deploy authorization.
