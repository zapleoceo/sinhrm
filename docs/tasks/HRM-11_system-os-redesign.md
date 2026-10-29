# HRM-11 — редизайн всей системы в Browser OS

План, scope и инварианты: ../ai-ui/tasks/system-os-redesign/task-context.md; implementation-plan.md. Jira — историческая связанная задача, её статус не приравнивается к выполнению нового объёма.

## Контекст з KB

Jira read-only29.10: HRM-11 (To do), HRM-84 unrelated superadmin. Прямая новая инструкция владельца и согласовательный v5 — текущая презентационная спецификация. Рабочее приложение — источник прав/поведения. Независимый preflight выявил зависимости moduleGuard/globalquery/navigation для multipleoutlets: сначала foundation, потом guardedroutingbridge; не подменять многоконность заглушками.

## Стан

2026-10-30 Asia/Saigon: isolatedworktree D:/Projects/HRM/worktrees/browser-os-system, branchfeat/browser-os-foundation, baseorigin/main899d4dab. Discoverycomplete:69unique routeimports,49E2Epages доизменений. Реализованыпредпочтения/AppearancePageUK/RU/EN,9unitPASS, targetedlintPASS; globalsystemtokens/materials+route/menuwiring ininitialLunabatch. Acceptance50pages+preferencesflow+actualinventoryevidencecapture added, parser/lint/tscPASS. FullCI/build/renderpendingdraftPR. SOLpreflight условноразрешилpublicnamedoutlets+scopednavigationfacade толькосguard/query/lifecycletests; отдельныйLuna началround2в D:/Projects/HRM/worktrees/browser-os-windows branchfeat/browser-os-windows (base899d4dab). Primaryroutingfoundationнеизменёнкроменовойsettingspage. Следующийшаг — freezefoundation, draftPR/CI+actualscreens+SOLreview, затемпродолжатьrealwindows/desktop/allmodulecoverage. Весьредизайнне завершён, productionнеизменён, merge/deployнет.
