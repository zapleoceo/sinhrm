# HRM-17 — revalidate mutable locale files

## Plan

1. Add client-side revalidation to Transloco's existing `/i18n/<lang>.json` request without changing its URL or the in-memory translation cache.
2. Add a focused HTTP test proving the request asks caches to revalidate and applies the latest response payload.
3. Document the loader contract and update the worklog.

## Context from KB

- Confluence search was attempted for `Transloco` and returned 403 (`Current user not permitted to use Confluence`); no KB context was available.
- Live evidence supplied with this task: fresh `GET /i18n/uk.json` returns HTTP 200 with the new translation keys, `Cache-Control: public, max-age=3600`, and no ETag. The browser can therefore reuse a stale translation file for one hour.

## State

- Time: 2026-10-30 15:54 Asia/Saigon.
- Worktree: `D:/Projects/HRM/worktrees/gitlab-glass-range`, branch `fix/locale-cache-revalidation`, verified base `6225cf01a8afb80fd3ad24ae1bfce9f28e13ec07` (`origin/staging`).
- Scope: only `src/app/core/i18n/transloco-loader.ts`, its focused spec, Core module documentation, this task note, and one worklog fragment.
- Completed: loader sends `Cache-Control: no-cache` on its existing URL; its focused test asserts revalidation and the fresh `appearance.chooseWallpaper` / `appearance.customWallpaper` payload. Core module help now describes the network cache contract. This does not disable Transloco's in-memory language cache or change locale URLs.
- Checks: `npx ng test --watch=false --include=src/app/core/i18n/transloco-loader.spec.ts` — 1/1 pass; `npx ng lint` — pass; `npm run test:docs` — 9/9 pass; `git diff --check` — pass.
- Not verified: actual browser reload against the deployed app; this worktree change must first be reviewed and included in the authorized release path.
- Dependency reuse: test dependencies were shared from the matching lockfile worktree through a temporary `node_modules` junction; the package versions matched exactly and the junction was removed after checks.
- Next: hand the exact diff to SOL review; after integration and release, verify the live UI picks up the fresh locale strings on an ordinary reload.
