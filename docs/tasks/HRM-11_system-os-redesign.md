# HRM-11 — редизайн всей системы в Browser OS

План, scope и инварианты: ../ai-ui/tasks/system-os-redesign/task-context.md; implementation-plan.md. Jira — историческая связанная задача, её статус не приравнивается к выполнению нового объёма.

## Контекст з KB

Jira read-only29.10: HRM-11 (To do), HRM-84 unrelated superadmin. Прямая новая инструкция владельца и согласовательный v5 — текущая презентационная спецификация. Рабочее приложение — источник прав/поведения. Независимый preflight выявил зависимости moduleGuard/globalquery/navigation для multipleoutlets: сначала foundation, потом guardedroutingbridge; не подменять многоконность заглушками.

## Стан

2026-10-30 Asia/Saigon: foundationworktree D:/Projects/HRM/worktrees/browser-os-system, branchfeat/browser-os-foundation, base899d4dab, draftPRhttps://github.com/zapleoceo/sinhrm/pull/247. Initialheadb92a4bc CI40919766554: frontend1186testsPASS/1skip, backend/extension/docs/worklogPASS; build/ui-parity остановлены shellCSS10.04kB>8kB, security остановлен существующимsymfony/mime7.4.19 CVE-2026-45070. НовыйлокальныйCSSфикс используетобщиеchrome/motiontokens; Sassshell7729bytes,34shelltests/lintPASS. SOL выявил mobiletestskippedвdesktopdescribe — тестперенесёнвmobileflows. Требуется новыйCIbuild/render иконкретныеappearance/menu inventories.

Реальныепредпочтения иsettingsUK/RU/EN реализованы,9unitPASS; sourcecontrast261pairs/9configsPASS. ВесьредизайнНЕзавершён: параллельноLuna пишет actualwindowbridge/pilot в browser-os-windows(feat/browser-os-windows) и desktopwidgets в browser-os-desktop(feat/browser-os-desktop), обаbase899d4dab. Следующее — pushfoundationcorrection/CI, SOLexactdiff+реальныеPNG; separatepatchпочтовойзависимости, далееинтеграцияокон/desktop/allmodules. Productionнеизменён, merge/deployнеразрешены. НикакихформальныхGitHubapproveилиliveperformanceclaims.
