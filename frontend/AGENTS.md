# SinHRM frontend — DOX root

Angular 22 SPA (standalone components, signals, Angular Material, Transloco ru/uk/en), Node 24. Standalone project split out of the SinHRM monorepo; the backend is a separate Laravel project reached only through `/api` and `/sanctum`.

## Project Rules
- Stack: standalone components, signals, no `any`, every UI string in `public/i18n/{ru,uk,en}.json`. Code layout: `src/app/core` (cross-cutting) + `src/app/features/<name>`.
- Production entrypoint is `server.mjs` (HTTP over the unix socket `APP_SOCKET`; TCP `PORT` is local only). Never ship `ng serve` or expose an app port. No Vercel: there is no `vercel.json`.
- One domain for front and back: the SPA calls only same-origin `/api/*` (Sanctum CSRF cookie: `/api/sanctum/csrf-cookie`). nginx routes `/api/*` and `/health` to the backend; `server.mjs` does not proxy. Do not hardcode a backend host or a non-`/api` backend path in the app.
- Env contract: `APP_SOCKET`, `PORT`, `NODE_ENV` (README). No secrets in this repo; it ships to browsers.
- `.nvmrc` pins Node 24 (matches `engines`); CI, deploy and local dev all read it. `run.sh` is the post-deploy hook: no DB, exits 0, never starts a process.
- Dockerfile, CI and deploy recipes live in the external ci-cd repo; do not add them here.
- Heavy tests (full `ng test`, `npm run e2e`) run in CI; locally lint and targeted `ng test --include <file>`.
- UI changes keep UI parity green (`docs/guides/ui-parity.md`); update snapshots only deliberately (`npm run e2e:update`, review the diff).
- A module's code change updates `docs/modules/<module>.md`; each change adds a fragment `docs/worklog.d/<YYYY-MM-DD>-<slug>.md` (format in `docs/worklog.d/README.md`).
- `docs/` is the single source of the in-app help. The backend (ADR, architecture, product, MySQL/handover guides) lives in the separate sinhrm.api repo; links to it are absolute URLs. Paths like `app/Modules/...` in module pages point to that repo.

## Verification
`npm run lint`, `npm test`, `npm run test:docs`, `npm run build`; UI parity: `npm run build && npm run e2e`. Server smoke: `APP_SOCKET=/tmp/a.sock node server.mjs`, then `curl --unix-socket`.

## Core Contract

- AGENTS.md files are binding work contracts for their subtrees; work must stay understandable from the nearest AGENTS.md plus every parent above it

## Read Before Editing

1. Read the root AGENTS.md
2. Identify every file or folder you expect to touch
3. Walk from the repository root to each target path
4. Read every AGENTS.md found along each route
5. If a parent AGENTS.md lists a child AGENTS.md whose scope contains the path, read that child and continue from there
6. Use the nearest AGENTS.md as the local contract and parent docs for repo-wide rules
7. If docs conflict, the closer doc controls local work details, but no child doc may weaken DOX

Do not rely on memory. Re-read the applicable DOX chain in the current session before editing.

## Update After Editing

Every meaningful change requires a DOX pass before the task is done.

Update the closest owning AGENTS.md when a change affects:

- purpose, scope, ownership, or responsibilities
- durable structure, contracts, workflows, or operating rules
- required inputs, outputs, permissions, constraints, side effects, or artifacts
- user preferences about behavior, communication, process, organization, or quality
- AGENTS.md creation, deletion, move, rename, or index contents

Update parent docs when parent-level structure, ownership, workflow, or child index changes. Update child docs when parent changes alter local rules. Remove stale or contradictory text immediately. Small edits that do not change behavior or contracts may leave docs unchanged, but the DOX pass still must happen.

## Hierarchy

- Root AGENTS.md is the DOX rail: project-wide instructions, global preferences, durable workflow rules, and the top-level Child DOX Index
- Child AGENTS.md files own domain-specific instructions and their own Child DOX Index
- Each parent explains what its direct children cover and what stays owned by the parent
- The closer a doc is to the work, the more specific and practical it must be

## Closeout

1. Re-check changed paths against the DOX chain
2. Update nearest owning docs and any affected parents or children
3. Refresh every affected Child DOX Index
4. Remove stale or contradictory text
5. Run existing verification when relevant
6. Report any docs intentionally left unchanged and why

## Child DOX Index

- [src/app/AGENTS.md](src/app/AGENTS.md) — application code: core, features, i18n, routing
- [server/AGENTS.md](server/AGENTS.md) — on-site HTTP server helpers: static serving, backend-path guard, security headers
- [scripts/AGENTS.md](scripts/AGENTS.md) — build-time docs bundle and node tests
- [e2e/AGENTS.md](e2e/AGENTS.md) — UI parity harness (Playwright)
- [docs/AGENTS.md](docs/AGENTS.md) — Markdown source of the in-app help
