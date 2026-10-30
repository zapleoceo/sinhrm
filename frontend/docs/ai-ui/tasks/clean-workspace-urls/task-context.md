# HRM-16: clean workspace URLs

## Scope

Keep Angular's named-outlet UrlTree as the authoritative routed workspace so all existing auth and module guards continue to run. Use Router `browserUrl` for the visible canonical active-module URL. Store only bounded route IDs, slots, window controls and existing bounded per-window query state in tab-scoped session storage, namespaced by authenticated user. Keep each history entry's workspace snapshot in Angular navigation state; clean reloads restore at the guarded route match, and popstate uses `NavigationStart.restoredState` to start a normal guarded Router navigation. A clean shared URL without matching history state opens its canonical module in the primary outlet.

Persist window geometry separately per authenticated user, with finite/range validation and writes only after gesture/keyboard completion. Legacy named-outlet URLs remain directly routable.

## Safety and constraints

- No cookies, tokens, credentials, HR record content, or server persistence.
- Validate snapshot schema and re-check route/module authorization before restoring.
- Production navigation never mutates History API directly or bypasses Router guards. E2E may seed two captured valid app states with History API only as a test fixture to trigger real browser popstate.
- Do not change desktop/shell CSS.

## Validation plan

Test clean address bar vs internal URL, same-tab reload restoration, Back/Forward restoration, legacy auxiliary URL compatibility, inaccessible route rejection, and query/storage bounds. E2E exercises real Back/Forward between distinct module URLs; same-visible-URL layout/control changes replace the current history entry. Since debounced text filters replace rather than push, E2E may seed captured valid A/B snapshots as a test-only fixture before real `page.goBack()`/`page.goForward()`. Test geometry reload persistence and invalid data rejection. Run targeted Angular specs/lint and docs checks; parent handles full CI.
