# OS-style desktop reference

## User goal

Bring the signed-in desktop closer to the provided screenshot: grouped app shortcuts with distinct colors, a right-hand widget rail, a compact floating Dock, and a more expressive background.

## Evidence and reuse

- Reference: `D:/Temp/codex-clipboard-4c15a813-3912-42f6-9635-7078b175bf3e.png`.
- `src/app/features/desktop/desktop.model.ts` already owns `DESKTOP_APPS`, `DESKTOP_GROUPS`, role/module filtering (`appAllowed`), and real widget definitions/authorization (`widgetAllowed`). Reuse these; do not invent app entries or access checks.
- `DesktopDataStore` and its existing dashboard request are the only source for widget metrics. Keep loading/error/empty states and never add screenshot-only numbers.
- The existing shell Dock already handles pinned destinations and running-window substitution. Restyle its current controls without changing pinning/route behavior.
- `src/styles.scss` owns theme, wallpaper, glass, and fast-mode tokens. Keep the desktop treatment token-driven and disable expressive background work in fast mode.

## Layout direction

On wide screens, use a flexible app-group matrix in the main area with the existing widgets arranged in a narrower right rail. At tablet/mobile widths, collapse to a single readable flow, keep touch targets, and avoid forcing a narrow fixed rail. Keep the existing widget editor and drag/keyboard ordering.

## Constraints

No backend/API changes, fake widgets, new app routes, or relaxed authorization. Preserve direct routing, window launch semantics, all themes/color schemes, glass/solid choices, reduced motion, and fast mode.
