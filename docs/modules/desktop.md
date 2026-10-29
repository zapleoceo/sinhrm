# Desktop

`/desktop` is an authenticated, lazy-loaded landing surface inside the existing `ShellLayout`. The current `/` overview stays in place. The desktop route is a core page (`moduleForUrl('/desktop')` is `null`); it does not add or weaken route guards.

## Launcher access

The typed registry in `frontend/src/app/features/desktop/desktop.model.ts` contains real shell destinations and reuses the shell's Transloco labels and Material icons. Its visibility checks use the current user's **effective** roles and enabled module list, matching shell checks for HR, admin, superadmin, and module-owned links. The existing router guards and API remain the access authority. The launcher adapter defaults to `Router.navigateByUrl`; the injection token is the later integration seam for guarded route-backed windows.

## Widgets and data

`DesktopDataStore` requests the existing `GET /api/dashboard` through `OverviewService` once when the page opens, with an explicit refresh action and no polling. A change of account, effective roles, or module grants cancels the prior request and clears its result before loading again. Loading, error, absent optional data, and a real numeric zero have distinct UI states.

The current widgets show only the authenticated dashboard's aggregate candidate counts, current user's task counts, day-route interview/task counts, and available approval counts. They do not render names, task titles, candidate records, or approval rows. Dashboard results remain in memory and are never persisted by the desktop.

S/M/L changes the widget grid span on desktop. On phones the widgets use one column and retain the selected size through minimum content heights of 7.5rem, 9.5rem, and 11.5rem, respectively, so size remains perceptible without forcing a fixed height or clipping longer localized content.

## Per-user layout

`DesktopLayoutStore` stores only `{version: 1, widgets: [{id, size}]}` at `sinhrm.desktop.layout.v1.<userId>`. Layout validation requires exact object keys, version 1, recognized unique widget IDs, `s | m | l` sizes, and at most 12 entries. Empty layouts are saved. Missing, malformed, unavailable, or blocked browser storage falls back to the in-memory default layout without blocking use of the page. Current widget IDs are unique, so the active registry offers four kinds; the twelve-entry ceiling is a bounded schema limit for future unique widget types.

Cards use CDK drag sorting with a touch delay and explicit keyboard move-up/move-down buttons. S/M/L controls change responsive grid spans; normal CSS grid flow keeps placement collision-free, retains the page's scrollable extent, and collapses every size to one column on mobile. Focus remains on controls after resizing/reordering and moves to the matching add button after removal.

## Appearance and verification

The feature stylesheet uses existing `--app-*` and Material semantic tokens and reads the root appearance attributes (`data-theme`, `data-color-scheme`, `data-accent`, `data-wallpaper`, `data-interface-style`, `data-performance`, `data-motion`, `--os-glass-opacity`) when present. Root Material tokens supply theme/accent colors; wallpaper variants add a restrained semantic-accent wash. Glass is limited to launcher/widget chrome, with opaque content interiors, a 20px desktop and 10px mobile blur ceiling, opaque fallback, and fast/reduced-motion overrides after responsive rules. It does not edit global theme tokens or the theme service.

Focused tests cover registry access, strict storage parsing, account switching, blocked storage, widget data privacy/states, operations, and the guarded route placement. The UI parity inventory includes `/desktop`; its new rendered baselines require human review before snapshot acceptance. An E2E flow covers launcher routing and widget persistence/interactions using existing mocked dashboard fixtures. No live API or production performance claim is made here.
