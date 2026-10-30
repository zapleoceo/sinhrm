# Implementation plan

1. In `DesktopPage`, compute visible app groups from `DESKTOP_APPS` and `DESKTOP_GROUPS` via existing `appAllowed`; hide empty groups. Render anchors that keep native link behavior and send plain clicks through `DESKTOP_LAUNCHER`.
2. Place this launcher beside the existing widget section. Keep its API data, local persistence, drag/drop, resize and edit controls; use a narrow stacked layout below desktop widths.
3. Style app tiles by existing group identity, using current semantic/theme colors. Reuse wallpaper/glass tokens for the background and panel surfaces. Suppress the decorative background in fast mode.
4. Tighten the existing Dock dimensions only; keep pinned destinations, route authorization and running-window replacement intact.
5. Extend desktop unit tests for authorization/group hiding and launcher routing, extend the focused Playwright desktop flow for rendered app groups and window launch, and refresh desktop accessibility inventory snapshots through the project workflow.

## Reuse decision

Do not create another application catalogue, icon service, widget source, or permissions layer. Keep the six existing desktop groups and all entries supplied by the code registry.
