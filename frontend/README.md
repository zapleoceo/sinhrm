# SinHRM — frontend

Angular SPA of the SinHRM recruiting & HR platform. Standalone project: builds and runs without the backend or the monorepo.

## Build & run
```bash
nvm use            # Node 24 (.nvmrc)
npm ci
npm run build      # docs bundle (public/help/docs.json from ./docs) + ng build → dist/frontend/browser
npm start          # node server.mjs — serves dist (no proxy: nginx routes /api and /health to the backend)
npm run dev        # ng serve (needs a backend at /api; see proxy notes in docs/guides/development.md)
```
Checks: `npm run lint`, `npm test`, `npm run test:docs`, `npm run e2e` (UI parity, Playwright).

## On-site (ITSTEP) runtime
`server.mjs` is the only entrypoint. In production it speaks HTTP **over a unix socket** (nginx `proxy_pass`es to it); the TCP `PORT` is for local use.
Front and back share one domain: nginx sends `/api/*` and `/health?token=…` to the backend and everything else here. The server does not proxy; a backend path that reaches it gets a JSON 404.

| Env | Meaning |
|---|---|
| `APP_SOCKET` | Unix socket path to listen on (production). Unset → TCP. |
| `PORT` | TCP port when `APP_SOCKET` is unset (default `4200`). |
| `NODE_ENV` | `production` / `test` both serve the prebuilt SPA. |

`run.sh` is the post-deploy hook; the frontend has no database, so it exits 0. CI/CD and the Dockerfile live in the external ci-cd repo, not here.
The SPA sends security headers itself (`server/headers.mjs`, tested by `npm run test:docs`).

## Docs
In-app help («Довідка») is built from `docs/` (modules, guides, `worklog.d`) at build time. Agents: start with [AGENTS.md](AGENTS.md).
