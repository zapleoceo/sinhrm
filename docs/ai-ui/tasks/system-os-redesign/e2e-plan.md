# OS-01 appearance E2E coverage

## Scope and current evidence

- Add `/settings/appearance` to the real page inventory so both viewport sizes and both color schemes receive inventory, layout, error, and axe checks.
- Add a focused desktop-light flow that changes theme, reference/custom scheme, accent, surface style, wallpaper, transparency, and fast mode through the rendered controls; verify the shared `html[data-*]`/CSS contract and reload persistence.
- Add a mobile-light fast-mode acceptance flow with reduced-motion emulation disabled so fast-mode transition rules are measured independently. It enables glass and fast mode through UI controls, scans rendered elements and pseudo-elements for non-`none` filter/backdrop-filter, and checks opaque backgrounds plus zero transitions on `#app-sidebar` and `.topbar` before and after reload.
- The flow uses only the existing synthetic auth/API harness. Appearance preferences are local browser state; no API fixture or backend behavior is expected.
- The user-menu action uses role `menuitem` and the translated `appearance.title` label `Зовнішній вигляд`; a focused desktop-light flow verifies navigation to `/settings/appearance`.
- No CI build or full Playwright run was performed in this worktree. The route, page, and shell wiring have not yet been confirmed together in a rendered bundle.

## Snapshot and axe baseline handoff

`PAGES` currently describes 49 existing route entries before this addition. The new appearance page needs two viewport inventory snapshots (desktop and mobile; the light-theme snapshot is compared in both themes) and four axe rows (two viewports × two themes). Do not refresh all snapshots or bless violations.

For safe CI generation, add/use a snapshot-only update mode that writes inventories while retaining normal axe comparisons against an empty baseline for the new page. This makes any axe violation fail the focused run, and the baseline remains untouched. The current `E2E_UPDATE=1` mode is unsuitable for blind generation: it writes axe counts and merges them into `axe-baseline.json` automatically. No CI or harness-mode change is included here; until that mode exists, use a temporary CI checkout/artifact run, inspect counts and screenshots, and manually promote only clean appearance snapshots plus zero-violation rows after review.

On a normal run, parity now attaches the observed inventory JSON (including route path, declared state, page key, viewport and theme) to the Playwright result before snapshot assertions whenever the baseline is missing or differs. It captures the page screenshot before assertions when `E2E_SCREENSHOTS=1`, and also captures one for a missing baseline even without that flag. The existing CI uploads the Playwright report on failure and screenshots always, so a missing appearance baseline remains a failure with reviewable rendered/inventory evidence; this does not create or update baselines.

## Pending integration

- CI must build the final combined source and run the focused appearance flow plus the complete parity matrix. Record any failing/missing fixture, overflow, axe delta, or snapshot gap; do not infer success from syntax checks.
- Current suite inventories the pre-existing 49 route entries and their declared menu/dialog/tab states. The broader OS-04 route/dialog/table/form inventory, role-negative cases, keyboard/focus matrix, and production rendered evidence remain uncovered beyond this foundation check.

## Стан

2026-10-30 Asia/Saigon: added appearance route inventory and preference persistence/navigation flows; parity attaches actual inventories on missing/mismatched snapshots and captures screenshots before assertions. Added mobile fast-mode shell CSS acceptance across reload. No E2E snapshots, axe baseline, layout allowlist, or CI workflow changed. Local checks passed after additions: page syntax, ESLint on owned E2E files, and E2E tsc. Browser execution remains pending CI. Next: hand off for CI evidence.
