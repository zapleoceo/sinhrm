# Implementation plan — custom desktop wallpaper

## Files

- `src/app/core/theme/desktop-wallpaper.store.ts` (+ spec): validated and compressed image pipeline, single bounded storage key, CSS variable lifecycle.
- `src/app/features/appearance/appearance.page.ts` (+ spec): accessible file selection, preview, removal, localized error state.
- `src/app/features/desktop/desktop.page.html` / `.scss`: consume only the store's validated CSS variable as a static background layer.
- `public/i18n/{ru,uk,en}.json`: labels and errors.
- `e2e/flows.pw.ts`: generated PNG upload, reload persistence, removal, invalid format, screenshot attachments.
- `docs/modules/desktop.md`, `docs/tasks/HRM-15_custom-desktop-wallpaper.md`, `docs/worklog.d/2026-10-30-custom-desktop-wallpaper.md`.

## Sequence

1. Implement/test the image store in isolation, including decode/type/signature, dimension and encoded-size bounds, storage failures, and root style cleanup.
2. Wire the store into the appearance page and desktop background CSS. Do not add image payloads to theme preferences.
3. Add translated controls and errors, then component and E2E coverage.
4. Run focused unit specs, Angular lint, docs tests, E2E lint and `git diff --check`. Browser screenshots/build remain external CI evidence.
5. Update task state and hand off the frozen source to SOL.
