# OS-01 appearance E2E coverage

## Scope and current evidence

- Add `/settings/appearance` to the real page inventory so both viewport sizes and both color schemes receive inventory, layout, error, and axe checks.
- Add a focused desktop-light flow that changes theme, reference/custom scheme, accent, surface style, wallpaper, transparency, and fast mode through the rendered controls; verify the shared `html[data-*]`/CSS contract and reload persistence.
- Add a mobile-light fast-mode acceptance flow inside the mobile-only suite, with reduced-motion emulation disabled so fast-mode transition rules are measured independently. It enables glass and fast mode through UI controls, scans rendered elements and pseudo-elements for non-`none` filter/backdrop-filter, and checks opaque backgrounds plus zero transitions on `#app-sidebar` and `.topbar` before and after reload.
- The flow uses only the existing synthetic auth/API harness. Appearance preferences are local browser state; no API fixture or backend behavior is expected.
- The user-menu action uses role `menuitem` and the translated `appearance.title` label `Зовнішній вигляд`; a focused desktop-light flow verifies navigation to `/settings/appearance`.
- The routed OS shell preserves the primary appearance page query (`osPrimary=1`), so the navigation flow checks the pathname, primary marker, and heading inside `.primary-view` rather than requiring a query-free URL.
- Legacy sidebar-collapse/autohide coverage is mapped to the current Modules panel: persisted group expansion and keyboard navigation, plus close on Escape, outside click, and navigation. Mobile drawer targeting uses `.modules-toggle` because the Dock app-launcher can expose the same accessible label.
- Minimize controls are scoped to `.window-titlebar` with exact accessible-name matching because maximize controls contain the same translated word. Candidate widget launcher coverage now checks the routed OS window and its real heading while retaining fixture/API expectations.
- Already-running windows are focused through their `dock-window-os*` controls before interacting with an overlapped titlebar. The Candidates flow launches from the current Modules navigation rather than expecting a retired Dock pin.
- The People dynamic-route click focuses its window through the running Dock control after browser-history restoration, so the real link action is not intercepted by the active Tasks window.
- A rendered Modules-badge geometry check runs in all four desktop/mobile light/dark projects. It verifies the localized accessible name and non-overlap against the desktop text or mobile menu icon, while confirming the mobile text label stays hidden.
- The mobile minimize/restore check keeps a stable `os-window-os2` locator across both states and asserts its minimized class and hidden state before restoration.
- A focused `desktop-light` OS material flow reads computed styles from `.os-window.window-os1.open`, its titlebar/body, and a desktop widget/header/content ancestor. It drives the rendered transparency slider with focused native arrow-key input at 0/default/40, switches custom light/dark/reference palettes and solid/fast mode, then attaches state JSON and review screenshots; it does not update inventory or axe baselines.
- No CI build or full Playwright run was performed in this worktree. The route, page, and shell wiring have not yet been confirmed together in a rendered bundle.

## Snapshot and axe baseline handoff

`PAGES` currently describes 49 existing route entries before this addition. The new appearance page needs two viewport inventory snapshots (desktop and mobile; the light-theme snapshot is compared in both themes) and four axe rows (two viewports × two themes). Do not refresh all snapshots or bless violations.

For safe CI generation, add/use a snapshot-only update mode that writes inventories while retaining normal axe comparisons against an empty baseline for the new page. This makes any axe violation fail the focused run, and the baseline remains untouched. The current `E2E_UPDATE=1` mode is unsuitable for blind generation: it writes axe counts and merges them into `axe-baseline.json` automatically. No CI or harness-mode change is included here; until that mode exists, use a temporary CI checkout/artifact run, inspect counts and screenshots, and manually promote only clean appearance snapshots plus zero-violation rows after review.

On a normal run, parity now attaches the observed inventory JSON (including route path, declared state, page key, viewport and theme) to the Playwright result before snapshot assertions whenever the baseline is missing or differs. It captures the page screenshot before assertions when `E2E_SCREENSHOTS=1`, and also captures one for a missing baseline even without that flag. The existing CI uploads the Playwright report on failure and screenshots always, so a missing appearance baseline remains a failure with reviewable rendered/inventory evidence; this does not create or update baselines.

## Pending integration

- CI must build the final combined source and run the focused appearance flow plus the complete parity matrix. Record any failing/missing fixture, overflow, axe delta, or snapshot gap; do not infer success from syntax checks.
- Current suite inventories the pre-existing 49 route entries and their declared menu/dialog/tab states. The broader OS-04 route/dialog/table/form inventory, role-negative cases, keyboard/focus matrix, and production rendered evidence remain uncovered beyond this foundation check.

## Стан

2026-10-30 Asia/Saigon: appearance inventory/persistence/navigation flows and material captures are authored; parity attaches actual inventories on missing/mismatched snapshots and captures screenshots before assertions. CI 40951503654 found two desktop-light E2E issues: the People link was occluded by active Tasks after history restoration, and native Home did not reset a maxed range slider in this interaction sequence. The People flow now focuses its window before clicking; transparency uses focused, directional native arrow keys and asserts each intermediate value. A rendered Modules badge non-overlap check now runs in all four projects. The mobile minimize/restore check retains one stable window test ID and asserts minimized class plus hidden state before restoration. Static validation: `npm run e2e:lint`, `node e2e/run.mjs --list`, and targeted `git diff --check` pass; browser execution for these corrections remains pending CI. No E2E snapshots, axe baseline, layout allowlist, or CI workflow changed. Next: hand off for fresh browser CI evidence.
