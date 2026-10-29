# Project profile: Browser OS system redesign

Read-only discovery for the implementation work in `D:/Projects/HRM/worktrees/browser-os-system`.

## Verified checkout

- Branch `feat/browser-os-foundation`, clean at discovery start; `HEAD` `899d4dab4e4ebd9735d785142a0b0fa47b0ac76a`, tracking `origin/main`.
- Angular frontend is `frontend/`, with one public-facing SPA, Laravel API in the separate backend directory, and a browser extension. This task touches the frontend experience only.
- Angular 22.2.x, Angular Material/CDK 22.2.x, TypeScript 6, standalone components, signals, OnPush, lazy `loadComponent` routes, Transloco 8.4, RxJS 7.8. npm 11.17; Node 24 in CI.
- No server, database, build, or test suite was started for this survey.

## Architecture and conventions

- App bootstrap and providers: `frontend/src/app/app.config.ts`; routes: `frontend/src/app/app.routes.ts`; global tokens/reset/components: `frontend/src/styles.scss`.
- `core/` owns auth, routing guards, HTTP/CSRF, date/i18n, theme, and shared UI primitives. `features/<domain>/` owns route pages, domain components, services, stores, and local styles.
- Pages mostly use colocated SCSS or component-scoped styles in `.ts`; common surface/table/dialog styles are centralized in `styles.scss`. Presentational pieces use standalone Material/CDK components.
- UI copy and route titles use Transloco keys in `frontend/src/assets/i18n/{uk,ru,en}.json`; preserve all three locales, including any new OS controls and window accessibility labels.
- Existing design direction is “Route”: neutral canvas, accent reserved for status/actions, line-based cards, and explicit light/dark handling. See `docs/architecture/design-direction.md` and `frontend/src/styles.scss`.

## Runtime and verification surfaces

- Router has 76 lazy component references across 69 distinct imports (shared pages appear on multiple URLs). Authenticated routes are nested under `ShellLayout`; `/login`, `/jobs`, and `/jobs/:slug` are outside it.
- `ThemeService` is initialized by `provideAppInitializer` before `AuthService.load()`. It sets `html[data-theme=light|dark]`; explicit choice is persisted at `sinhrm.theme`, otherwise OS preference is used.
- CI workflow `.github/workflows/ci.yml` has frontend lint, unit tests/coverage, production build, and `ui-parity`. The latter uses production build + mocked API fixtures in headless Chromium, screenshots/inventory/layout/axe checks and interaction flows; screenshots are artifacts rather than pixel-comparison tests. Reference `docs/guides/ui-parity.md`.
- `frontend/e2e/pages.mjs` defines the manually covered page/state set; snapshots are at desktop 1440 and mobile 390, light and dark. Not every route or every live backend state is represented. Existing harness omits a running backend.
- User/project policy: keep full suite and E2E in CI; local app should not be bootstrapped for this discovery. Any shipped UI change must preserve E2E inventory and axe baseline, and a restyle must be reviewed against fresh rendered evidence.

## Design source boundary

Owner-approved v5 lives in the separate prototype worktree at `D:/Projects/HRM/worktrees/browser-os-design/docs/ai-ui/tasks/browser-os-shell/final-report-v5.md`, with an independent SOL PASS at `.../independent-review-v5.md`; portable v5 is `D:/Projects/HRM/tmp/browser-os-design/v5/SinHRM-OS-v5.html`. V5 is a visual reference for matte glass, depth, highlight and fast-mode behavior. It explicitly reports no production Angular performance measurements and is not an integrated production shell. This discovery treats those artifacts as design guidance, not as production implementation or runtime proof.
