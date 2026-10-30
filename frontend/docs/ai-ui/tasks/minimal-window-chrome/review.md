# Independent minimal window chrome review

Reviewer: SOL. Date: 2026-10-30 Asia/Saigon. Base: be46503a3ab69e2265d8b54b4f7a73a70b3bcc83. Worktree: D:/Projects/HRM/worktrees/gitlab-browser-os, feat/minimal-window-chrome.

Reviewed complete base-to-candidate tracked and untracked scope, workspace template/styles/spec, module help, task/context/plan and worklog. Root/app/docs DOX contracts re-read. Source scope is window UI only; routing, geometry algorithms, HTTP, server and dependency graph unchanged.

Compact native controls preserve translated accessible names, semantic button keyboard activation, event handlers and pointer guard excluding button descendants from titlebar dragging. SVGs are hidden from accessibility and pointer targeting. Existing titlebar keyboard movement, resize keyboard handler, focus/capture/cancel handling remain unchanged. Center title uses bounded ellipsis; desktop columns balance; mobile controls/groups do not shrink and retain 44px targets. Resize affordance remains 32px with keyboard/focus treatment. Shared motion token preserves fast/reduced mode.

Earlier draft issues (text glyphs, mobile flex shrink) corrected before freeze. P2 glyph contrast against semantic colored discs identified: app-muted is inappropriate on dark-theme pastel fills. Requested bounded control foreground change to mat-sys-surface, leaving reset muted. Correction saved and independently verified: window-control uses mat-sys-surface; reset remains muted. Final SOURCE publication gate PASS, no unresolved P1/P2 findings.

Writer reports focused specs 7/7, Angular lint and diff check passing. Reviewer ran diff check and inspected existing behavior assertions; no full local suite/server/build. CI and actual desktop/mobile screenshots are required before release, including focus/hover icon readability and smallest viewport title/control containment. This artifact does not claim visual approval of an unrendered candidate.

DOX pass: evidence-only review adds no new durable ownership/index contract; AGENTS files intentionally unchanged.
