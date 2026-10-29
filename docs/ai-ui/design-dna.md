# Design DNA: current SinHRM Angular UI and approved OS direction

## Existing system

- `frontend/src/styles.scss` is the canonical global theme: Material system tokens plus `--app-*` semantic aliases, global `.panel`, table, status pill, dialog/menu/snackbar, board, and focus styles.
- Existing supported palette mode is light/dark. CSS uses `light-dark()` for theme-aware values and `ThemeService` applies explicit `html[data-theme]`, which sets `color-scheme`; if no saved value exists the service follows `prefers-color-scheme`.
- The foundation now keeps the quiet neutral surfaces and 1.5px line language, with 12px panels, user-selectable primary accent, and a platform system sans-serif stack (monospaced system fallback for technical values). Contrast checks remain part of `scripts/contrast-check.mjs`; account for every accent/scheme in rendered review.
- Most page styling references Material or app tokens. A bounded source scan found 11 literal-color lines in 8 of 284 non-test feature `.scss`/`.css`/`.ts` style-bearing files: login artwork, org chart palette/rendering, shell styling, and time-off settings. Canonical global tokens themselves necessarily hold palette values. Recheck the scan before final token migration; these are touchpoints, not a complete count of color declarations in templates/assets.

## v5 reference qualities

- Matte, restrained transparency on outer widgets, system chrome and window chrome; dense/opaque content interiors for legibility.
- Static angled highlights, thin edge treatment, bounded shadow. Light, dark and Sintegrum reference schemes use shared semantic CSS tokens.
- A user-controlled transparency amount is a material preference (0% opaque; prototype examples at 30% alpha .64 and 40% alpha .44), not a physical glass model.
- Fast mode removes blur, filters, glass gradients and shadows, yielding opaque surfaces. Unsupported `backdrop-filter` has an opaque fallback.
- Desktop prototype blur is 20px; mobile 10–11px. No continuous animation or pointer tracking was added.
- The reviewer opened seven renders and passed source/fallback review. They explicitly did not measure production CPU/FPS/memory, and the prototype cannot establish that Angular's full page tree is performant.

## Recommended production token bridge

Keep `ThemeService`'s light/dark selection and persistence as a distinct axis. Add a separate, persisted OS appearance/material state at the global theme boundary rather than introducing page-specific theme forks. Define semantic `--os-*` design tokens centrally and map them into existing `--app-*` and Material surface/outline/elevation tokens in `styles.scss`. This gives the existing token-consuming feature styles the whole-app palette/material change without rewriting every component. Use a small number of explicit `data-os-*` root attributes for scheme/material/performance and let styles own the rendering; avoid dynamically patching inline styles into routed pages.

Bridge coverage must include Material overlays rendered under the CDK overlay container, app-level dialogs/menus/snackbars, the shared shell, and global panels/tables. Keep page content surfaces sufficiently opaque over glass; do not apply backdrop blur to each feature card, table row, chart mark or full page. Migrate literal exceptions intentionally: login artwork remains branded/legible, org chart's semantic node categories retain distinctions, and status colors continue to encode meaning.

## Application surface boundaries

- Global typography, base canvas and tokens should reach all routes, including guest login and public career pages. Keep those route experiences visually self-contained: they do not get logged-in OS windows, taskbar, desktop state or user-specific persistence.
- The authenticated OS workspace belongs under `ShellLayout` (currently nested behind `authGuard` and `moduleGuard`); root app/global theme affects every page. Do not put authenticated windows or identity-linked state in the root app host.
- Responsive system chrome should degrade to a compact mobile navigation/window experience; glass and overlays must not cause horizontal overflow or obscure focus/controls.
- Follow `prefers-reduced-motion`; fast/performance setting should be independently testable and force opaque/lightweight CSS without changing functional state or route availability.
