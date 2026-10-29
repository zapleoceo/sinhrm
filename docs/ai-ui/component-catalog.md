# Component reuse catalog for the OS redesign

This is a bounded inventory of reuse seams; it does not authorize replacing domain behavior with prototype mockups.

| Existing seam | Location | Reuse for OS work |
|---|---|---|
| App-wide theme/material tokens and global primitives | `frontend/src/styles.scss` | Primary bridge for canvas, chrome, panel, semantic status, focus, table and Material token styling. Extend aliases before editing many feature pages. |
| Theme choice | `frontend/src/app/core/theme/theme.service.ts` | Preserve light/dark initialization, OS preference fallback and safeStorage persistence; add orthogonal appearance/material preferences here or in a focused adjacent service. |
| Signed-in app frame and navigation state | `frontend/src/app/features/shell/shell.layout.{ts,html,scss}`, `nav-rail.ts`, `nav-groups.ts`, `nav-badges.ts` | Rework chrome and route launching here. Retain role-aware nav, route-derived active group, badges, drawer/collapse/auto-hide and Escape/focus behavior. |
| Dialog defaults and viewport sizing | `frontend/src/app/core/ui/dialog.ts` | Existing shared Material dialog config, `wideDialog`, max 95vw, focus first field and restore focus. OS window controls must not override form dialogs or keyboard/focus semantics. |
| Notifications | `frontend/src/app/core/ui/notify.service.ts` | Keep toast APIs and `styles.scss` Material snackbar theme mapping. |
| Data tables and route state | `frontend/src/app/core/ui/table/*`, `frontend/src/app/core/ui/paginator-intl.ts` | Shared pagination/sort/filter/request primitives, URL persistence and localized paginator. Retain table controls/columns/inventory. |
| Nav logo and icons | `frontend/src/app/core/ui/logo.ts`, `channel-icon.ts`, `channel-icons.ts`; Material Symbols configured in `app.config.ts` | Reuse for launcher/sidebar/window iconography; avoid duplicating SVG/icon logic. |
| Employee picker / dialogs | `frontend/src/app/features/people/picker/`, `people/profile/*.dialog.ts`; `features/recruiting/*` | Existing selection dialogs, profile tabs, form and board workflows; preserve their data/state transitions as window content. |
| API, identity, access and i18n | `frontend/src/app/core/auth/*`, `core/http/*`, `core/i18n/*`, route guards | Remain the authority for visibility, auth, CSRF, language and URL titles. Presentation changes should not relocate these contracts. |
| E2E harness and snapshots | `frontend/e2e/pages.mjs`, `parity.spec.ts`, `flows.pw.ts`, `__snapshots__/` | Extend route window states and interaction coverage; compare snapshots intentionally and preserve accessibility/action inventory. |

## Existing content patterns to host

- Lists and server-backed tables, profile tabs, calendars, Kanban boards/drag-drop, wizards/editors, reports/charts, public application pages, and dialogs are domain-owned route/components. A host should render these real components and route data, not duplicate their HTML/data into a second “OS” implementation.
- CDK `OverlayContainer` hosts Material overlays outside normal feature component trees. Ensure root token and theme attributes cascade to it; don't rely on selectors scoped to shell component encapsulation for dialogs.
- The only strongly route-sensitive form exit contract found is vacancies create/edit `canDeactivate: page.canLeave()` with autosaved draft restore in `vacancy-form.page.ts`. Other modules have independently saved/dirty/publish contracts; inspect each before wrapping or changing route lifecycle.
