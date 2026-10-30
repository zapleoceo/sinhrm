# Independent source review — OS desktop reference

Reviewer: SOL. Date: 2026-10-30 Asia/Saigon. Worktree: D:/Projects/HRM/worktrees/gitlab-browser-os, feat/os-desktop-reference. Base: 75db9708287283ac1efd09aa51e3c59ec740ef92.

SOURCE publication gate PASS. Reviewed full base-to-working-tree scope, tracked and untracked task documents, and actual supplied reference image. No unresolved P1/P2 source finding.

App groups reuse DESKTOP_APPS/DESKTOP_GROUPS and appAllowed with empty groups removed; ordinary route links reuse existing launcher while modified clicks keep browser semantics. No fabricated modules/metrics, access expansion, API/model/state-persistence change or window algorithm change. Existing live widgets, loading/error/empty states, controls and reorder behavior remain.

Launcher no longer carries panel class; its styling avoids prior shared-panel cascade conflict. Rail sizing specificity intentionally preserves S/M/L width/density, with mobile full width and existing height differences. Container query wraps narrow widget controls. Dock uses border-box and bounded internal horizontal scroll; coarse-pointer controls retain 44px sizing. New tests add checks rather than weaken existing behavior assertions.

Preliminary P2 fixed before freeze: category panels now use shared os-chrome-background token rather than hardcoded transparency, preserving solid/fast contracts. Fast removes new decorative background/shadows. Icon foreground pairs with semantic light/dark palette; actual rendered contrast still requires observation.

Writer reports 42 focused specs, Angular lint, 9 documentation tests, e2e lint and diff check passing; reviewer independently ran diff check and inspected changed tests. No local full build/server/browser suite run.

Release gates remain: exact source/mirror identity; full CI; deliberate actual-inventory snapshot update after launcher additions (no blind snapshot blessing); inspect screenshots at 768/1000/1440 plus mobile rail/Dock, theme/solid/fast states; staging build/deploy and real-domain verification. SOURCE PASS is not visual or release completion.

DOX pass: module docs/worklog/task state updated; review adds evidence without new durable ownership or child index. Root/app/docs contracts unchanged.


## CSS budget extraction follow-up

SOURCE publication gate PASS for frozen follow-up against 9519c145e3d6c4ab3dae0710dfc2fa74ce575b74 in gitlab-glass-range. New standalone DesktopAppLauncherComponent owns catalog template/styles with readonly typed groups input and native MouseEvent/route output. Parent retains authorization grouping and launcher behavior; DOM selectors, translated names, href and modified-click behavior preserved.

Moved launcher styles retain four/three/two category breakpoints, shared appearance background, fast shadow suppression and focus treatment. Removed obsolete 12-column widget cascade because rail is always one column. S/M widths/density, L stretch, mobile height differences and narrow-card container wrapping remain. Removed local panel duplicate matches unchanged global panel tokens. Shell dedup retains chrome layer order, Modules layout, Dock containment, coarse44px targets and category color semantics. No global CSS or budget configuration altered.

Writer reports Sass compressed estimates desktop7183, child2616, shell7795 bytes; focused desktop10, lint and e2e lint7 pass. Reviewer independently ran diff check. Production Angular scoped-CSS byte sizes and visual parity still require CI; estimates alone do not prove the8KB build gate. Combined wallpaper/range integration must retain wallpaper first background layer and receive exact combined review.
