# Minimal window chrome

## Scope

Restyle the title bar controls and resize affordance in the browser workspace while preserving all current actions, labels, keyboard behavior, pointer handling, geometry, and mobile access.

## Visual direction

Use a compact glass title bar, small traffic-light action controls on the left, a centered truncating title, and a quiet geometry reset on the right. Keep 44px mobile control targets. Render the resize grip as a subtle corner detail with a generous focusable hit area.

## Constraints

Edit only `os-window-workspace.ts`, its SCSS and spec, `docs/modules/shell.md`, this task folder, `docs/tasks/HRM-12_window-chrome.md`, and one worklog fragment. Preserve the existing chrome background/filter contract and body opacity. No route, business, or geometry algorithm changes.
