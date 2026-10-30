# Implementation plan

1. Replace bulky Material icon buttons with small native controls that keep the translated accessible labels and existing click handlers.
2. Use a compact 44px title bar with left-side actions, a centered ellipsized title, and a right-side reset control.
3. Reduce the visible resize affordance while preserving its keyboard and pointer hit target.
4. Add focused behavior coverage and update shell help/worklog.

## Validation

Run the focused Angular spec, shell lint, documentation tests, and inspect desktop/mobile rendered output when available. Do not launch a local app or full suite.
