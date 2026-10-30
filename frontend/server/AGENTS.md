# server

## Purpose
Pure helpers for `../server.mjs` (the production server): `headers.mjs` (SPA security headers, API path predicate) and `static.mjs` (safe file lookup, MIME, cache policy).

## Local Contracts
- `server.mjs` serves `dist/frontend/browser`, does not proxy (nginx routes `/api/*` and `/health` to the backend; such a path reaching the server gets a JSON 404), falls back to `index.html` for extension-less paths, answers 404 for missing assets and 405 for non-GET/HEAD outside the API.
- Production binds `APP_SOCKET` (unix socket); `PORT` is local only. Security headers apply to SPA responses.
- Keep logic here dependency-free (node built-ins) and covered by `scripts/server-*.test.mjs`.

## Verification
`npm run test:docs`.
