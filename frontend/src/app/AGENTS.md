# src/app

## Purpose
The SPA: `app.config.ts` / `app.routes.ts` (lazy routes), `core/` (api, auth, date, errors, http interceptors, i18n, storage, theme, ui) and `features/<name>/` (one folder per backend module, plus `shell`).

## Local Contracts
- Standalone components, signals, no `any`. Features import from `core/`, never from each other's internals.
- HTTP goes through `core/api` with the CSRF and server-error interceptors; paths are relative (`/api/...`), the host is `server.mjs`'s concern.
- UI strings only via Transloco keys in `public/i18n/{ru,uk,en}.json` (all three languages).
- Table pages follow the shared table-page pattern (`src/testing/table-page.ts`, `docs/guides/tables.md`).
- Workspace routes declare their stable `windowRoute` metadata and use the shared window context/query adapters; do not build per-window query state in feature components. Primary-only query parameters are declared in route metadata. See [desktop.md](../../docs/modules/desktop.md).
- Appearance and desktop layout preferences are browser-local; they do not imply a backend persistence contract. Keep role/module authorization checks on desktop widgets aligned with the existing auth module map.

## Verification
`npx ng lint`; targeted `npx ng test --watch=false --include <file>`; full suite in CI. Every route/page change keeps `npm run e2e` (UI parity) green.
