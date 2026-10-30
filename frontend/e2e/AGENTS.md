# e2e

## Purpose
UI parity harness (`docs/guides/ui-parity.md`): Playwright against the production build with a recorded mock API (`fixtures/`), inventory snapshots (`__snapshots__/`) and an axe baseline.

## Local Contracts
- `serve.mjs` serves `dist/frontend/browser`; `record-fixtures.mjs` re-records fixtures from a local backend (a separate project now) and is not run in CI.
- Snapshots/baseline change only deliberately: `npm run e2e:update`, review the diff.

## Verification
`npm run build && npm run e2e`; harness itself: `npm run e2e:lint`.
