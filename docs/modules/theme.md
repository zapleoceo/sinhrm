# Appearance and shared design tokens

## What this module owns

The frontend appearance settings change locally stored presentation preferences. They do not change account roles, enabled backend modules, API behavior, or business data. The signed-in settings page is `/settings/appearance`; it is behind the authenticated shell and the ordinary core-module guard, with no admin-only role guard.

`frontend/src/app/core/theme/theme.service.ts` is the preference authority. `ThemeService.theme()` continues to expose the effective `light | dark` value for existing consumers. It reads `sinhrm.appearance`; when no valid new settings exist it migrates the legacy `sinhrm.theme` choice and otherwise follows the browser's light/dark and reduced-motion preferences. Malformed/out-of-range storage values are sanitized to allowlisted defaults. Settings remain in this browser's safe storage; they are not synchronized to the server.

## Root DOM and CSS contract

The service applies these attributes to `<html>` so the global stylesheet, all lazy pages and the CDK overlay container see one appearance state:

| Attribute/property | Allowed values / meaning |
|---|---|
| `data-theme` | Effective `light` or `dark`; keeps Material native controls and existing components compatible. |
| `data-color-scheme` | `custom` or the reference `sintegrum` scheme. The latter forces dark color-scheme at the root. |
| `data-accent` | `blue`, `violet`, `green`, or `graphite`; affects the primary interactive/brand channel. |
| `data-interface-style` | `glass` or `solid`; glass is restricted to shell chrome. |
| `data-wallpaper` | `mist`, `sand`, or `dusk`; subtle page-canvas backdrop only. |
| `data-performance` | `standard` or `fast`; fast disables blur and decorative backdrop/shadow effects. |
| `data-motion` | `normal` or `reduced`; reduced motion is honored by shell transitions and token-based timing. |
| `--os-glass-opacity` | Percentage `(100 - transparency)%`, used only by shell glass surfaces. At 0% transparency this is fully opaque. |

`frontend/src/styles.scss` maps those values into the existing Material system variables and `--app-*` semantic aliases. It is the shared source for app canvas/cards, controls, form fields, tables, status pills, dialogs and menus. Solid pages/forms/dialogs are kept sufficiently opaque; wallpaper and glass never lower text/control opacity.

## Color and material rules

- Accent is a presentation choice. Success, warning, error, hiring stage and chart meaning retain their semantic palette when the accent changes. Board stage colors are stable by stage type, never by column order.
- Sintegrum uses the reference purple/slate surface palette, with green success and amber warning retained. Returning to custom permits the chosen custom accent and light/dark preference.
- Glass uses a translucent shell sidebar/topbar, static surface color and bounded 20px desktop / 10px mobile blur. Unsupported `backdrop-filter` uses a solid opaque surface. Tables, page cards, charts, form controls and Material overlay menus/dialogs do not get nested glass blur.
- Fast mode makes shell surfaces opaque, removes blur, backdrop wallpaper and shared decorative shadows. Reduced-motion preference avoids shell movement. Appearance changes never alter routes/actions/data.
- Text uses the platform system sans-serif stack; no external Google font request is needed. Material Symbols are bundled from the existing local Apache-2.0 package dependency.

## Verification

`frontend/scripts/contrast-check.mjs` reads the root tokens from `styles.scss` and computes text/UI contrast for every custom accent in light and dark mode plus the Sintegrum scheme. Run with `node scripts/contrast-check.mjs` from `frontend/`. Angular route/menu and theme preference behavior have targeted unit tests; the full app build and rendered accessibility/visual checks remain CI gates.
