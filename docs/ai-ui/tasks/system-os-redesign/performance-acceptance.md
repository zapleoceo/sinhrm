# Browser OS performance evidence

The CI-only Playwright flow `frontend/e2e/os-performance.pw.ts` launches Tasks, People, Desk, and Reports through the real OS Dock and captures a viewport screenshot of their routed windows. It then records measured open/focus/minimize/restore durations, 180 raw `requestAnimationFrame` intervals, viewport/browser/fixture context, and JavaScript heap values only when the browser exposes them.

The acceptance gates are structural: all four routed views remain mounted, repeated focus/minimize/restore adds no API requests, and fast/reduced settings produce the expected root attributes plus computed no-filter/zero-transition values on rendered OS chrome. The existing appearance settings flow remains in `frontend/e2e/flows.pw.ts`.

Timings and frame intervals are evidence attached when CI executes; this branch has not generated browser timings locally. The test defines no frame-rate threshold and does not claim a performance improvement from raw samples. The attached screenshot is for visual review, not a baseline approval.
