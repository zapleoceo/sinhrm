# HRM-15 task context

- Request: allow the user to choose a local desktop background image, preview it, remove it, and return to the built-in wallpaper.
- Reference: current desktop style from HRM-13; no new visual mockup was supplied for the appearance settings controls.
- Existing contracts: `ThemeService` owns compact theme preferences and root appearance tokens; desktop background is rendered in `desktop.page.scss`; the appearance page is a standalone Reactive Forms component with Transloco-only strings.
- Data constraint: persist one optimized, validated raster data URL in a separate bounded browser-local key, never in `AppearancePreferences`; no upload, URL import, or remote fetch.
- Formats: JPEG, PNG, WebP only. Verify MIME and file signature, decode before accepting, constrain dimensions to 2048 px, and enforce a strict stored URL limit below 1 MB.
- Behavior: preset wallpaper remains underneath as fallback; custom image is static and remains enabled in fast mode without blur, transitions, or animation. Existing theme, palette, interface style, transparency, motion, widgets and layout remain intact.
- Errors: localized messages for unsupported/invalid images, excessive input size, compression failure, and unavailable/quota-exceeded storage.
- Review evidence: targeted unit tests; E2E with generated valid PNG fixture covering upload, persistence after reload, remove, unsupported file failure; attach appearance and desktop screenshots.
