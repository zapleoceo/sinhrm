# scripts

## Purpose
`build-docs.mjs` bundles `docs/` into `public/help/docs.json` (in-app help, run by `npm run build`); `worklog-build.mjs`, `worklog-lib.mjs`, `git-diff.mjs` build the «Журнал работ» page (PR numbers need full git history, otherwise blank); `contrast-check.mjs`, `gen-icons.mjs` are manual tools; `server-*.test.mjs` and `build-docs.test.mjs` are the node tests.

## Local Contracts
- Node built-ins plus `marked`; no other dependencies. `public/help` is generated and git-ignored.

## Verification
`npm run test:docs`.
