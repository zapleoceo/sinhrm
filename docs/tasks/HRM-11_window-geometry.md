# HRM-11 — real window geometry

## Plan

Implement pointer-based desktop drag and resize for real routed windows in `OsWindowWorkspace`; keep all geometry in the component session state. Add a pure bounded geometry helper for safe positioning, minimum sizes and viewport clamping. Preserve route component instances and do not navigate or persist data during pointer movement. Keep full-area mobile behavior and disable geometry gestures on narrow layouts. Provide keyboard arrow-key move/resize controls and a translated reset-geometry action. Add pure helper tests, component regressions for live route retention, pointer termination and min/max geometry, focused Playwright drag/resize/bounds checks, and module/worklog documentation.

## State

2026-10-30 01:50 Asia/Saigon. SOL found a fourth bounded issue: a second pointer could replace a live gesture. `startGesture` now rejects additional pointers before focus/capture/state changes. Added a component regression proving the first pointer remains authoritative, its lost-capture listener is removed at completion, and active capture/listener cleanup occurs on component destroy. Focused geometry/workspace tests pass 10/10; targeted Angular lint passes. The previous E2E lint/parser/TypeScript (5/5), safe-band flow, compressed Sass check (1,985 bytes), docs tests/links (29/29; 202 files, 0 dead links), and diff check remain unchanged; browser execution still awaits CI. Next: refresh the corrected full source patch for SOL/root review.

## Validation boundary

Local app/server, full build, and full E2E are prohibited for this task. Run only focused unit tests, lint, E2E static checks and compressed component Sass measurement. CI/browser rendering, pixel review, and combined semantic validation remain pending.
