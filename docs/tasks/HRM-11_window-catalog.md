# HRM-11 — all-module OS window routing compatibility

## Goal

Expand the reviewed real named-outlet pilot from Tasks/People to every authenticated module route while preserving the
original route records, guards, path/query contracts, page inputs, unsaved work, and normal browser navigation. Keep
login, public careers, module-off fallback, OAuth callback processing, and wildcard handling in primary routing.

## Plan before implementation

1. Replace the two-ID/path registry with explicit catalog metadata on the existing authenticated `Route` objects, and
   reuse those route records for primary and both named outlets. Teach the guard to validate metadata and current module
   access without weakening auth/role rules. Test catalog coverage and exclusions.
2. Generalize the current scoped Router facade and window query codec to dynamic routes and complete Router commands /
   UrlTrees. Reuse `TableQueryAdapter` for `ClientTable` and known direct query readers/writers; reject unsupported
   in-window state instead of falling through to primary.
3. Make auxiliary close/replacement honor `canDeactivate`, add guards for dirty editors that currently lack one, and
   make the vacancy polling loop pause while minimized. Preserve identity/role/module invalidation.
4. Add focused Angular router/adapter/guard tests and representative Playwright scenarios for dynamic detail navigation,
   namespaced filters in two slots, fragment/query preservation, deny/cancel paths, and minimized lifecycle.
5. Update affected module docs and add the required worklog fragment; run changed-spec tests, lint, E2E static checks and
   repo docs/worklog checks. Full E2E/build and live server checks remain CI-only.

## State

2026-10-30 Asia/Saigon. Branch `feat/browser-os-route-catalog`, worktree
`D:/Projects/HRM/worktrees/browser-os-route-catalog`, reviewed source SHA `38965b81d483bbeff7a2f5af29b212d4ac3c7c42`.
Read the all-route inventory at `D:/Projects/HRM/docs/tasks/system-os-window-compatibility.md`. Existing authenticated
route records now carry route/module metadata and are reused verbatim in all four named outlets; public/login/module-off
routes stay primary. `moduleGuard` validates the route metadata and each slot is bound to the identity + role scope
that opened it. Scoped Router navigation preserves dynamic path segments, matrix params, query values and fragments;
table state and direct query readers (including Org Chart root, Reports saved id and Time week) use a shared adapter.
Four named outlets are generated from one `WINDOW_SLOTS` list; `openAvailable` returns false at capacity and leaves
all mounted routes untouched. A fifth-page allocation cannot evict a dirty route.
Dirty vacancy, hiring, workflow and script editors have leave guards. Security scope invalidation lets a stale window
close through those guards, while ordinary close still honors user cancellation. Vacancy AI polling waits until its
window is visible before sending another request.

Added RouterHarness coverage for complete route reuse, module denial, dynamic `:id`, query-derived root/saved values,
primary query preservation, fragments, multi-command/relative RouterLink hrefs, table query edits, close cancellation,
role-only invalidation of a dirty window, canonical query replace/merge/preserve/null semantics, and bounded observer
lifetime across repeated child route replacement under the persistent host adapter. A guard regression uses generated
core, module and role-restricted route metadata. Added feature tests for Assets, Desk, hiring requests, Integrations, Mail,
Recruiting reports/vacancy forms, saved Reports, Scripts, Time and Workflows, including isolated query reads, dirty
leave cancellation/security cleanup and minimized vacancy polling. Added a Playwright case for a dynamic People detail
link from a window. The prior focused route and feature run passed (16 files, 150 tests); after the residual facade
change, the two directly affected specs pass (27 tests), including the new nonempty-primary query-only/relative/absolute
case. `npm run lint`, `npm run e2e:lint`, `npm run test:docs` (6 tests) and repo docs links (204 files, 0 dead links)
pass after the residual change. The staged-scope docs/tests helper passes against base `899d4dab`; docs link and docs-build
tests pass. All-four normal/minimized window chrome is a separate Geometry + Composition dependency: the integrated
candidate must provide distinct reachable controls at desktop and mobile sizes and verify screenshots before any
four-window usability or whole-system readiness claim. The pilot CI report E2E failures were
traced to the generic `.primary-view` wrapper being a `<section>` and therefore broadening existing section-scoped
selectors; it is now a `<div>` and the parity tests keep their expected rows unchanged. CI must rerun to verify the
reported regressions clear. Full browser E2E/build remain CI-only under repository rules. Still pending: independent
review of the correction batch, CI browser matrix, integrated primary OS chrome and Dock proof, all-four normal and
minimized window reachability, and explicit acceptance. No commit, push,
deployment, or server test performed.
