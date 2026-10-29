# HRM-11 — настоящий рабочий стол и виджеты

## План

Владелец поручил весь Browser OS редизайн; reference v5 D:/Projects/HRM/tmp/browser-os-design/v5/SinHRM-OS-v5.html и концепция групп модулей/информационных виджетов https://sintegrum-os-desktop.vercel.app. Это реальный Angular, не мокап.

1. Lazy DesktopPage `/desktop`, группы разрешённых модулей с реальными canonical links иконками/переводами, виджеты на рабочем столе: добавление/удаление/перемещение/размерSML. Пока не менять rootoverviewURL до общей интеграции.
2. Typedregistry реальных приложений по текущим shell/nav/role/module правилам. Hidden/disabledmoduleнепоказывать; настоящиеRouter/APIguardsсохраняются. FallbacklauncherчерезнастоящийRouter; отдельныйDIlaunchadapterпозволяет интегрировать guardedWindowNavigation послеround2, неfakewindows.
3. Данные только изсуществующихреальныхsummary/services: переиспользоватьOverview API, конечныезапросы, никакихсинтетическихсотрудников/счётчиков/встречv5. Пустое/ошибка/загрузкаотличимыотнуля. Датавиджета — текущаялокализованнаядата, небаннервстречизпрототипа.
4. Layoutperusercosmeticmetadata, max12, строгиеenum/id/number/duplicate/size/JSONchecks иblockedstoragefallback; пустаяраскладкасохраняется, сменаuser/roleнепоказываетчужойlayout/данные. НикакихPII/forms/APIpayloadвstorage.
5. CDKDrag либоboundedpointerdragбезcontinuousloop, клавиатура/touch, sizeawarecollisionfreeplacement/scrollableextent, mobilecolumnсфактическимиразнымивысотами. Нельзяпотерятьфинальныйdrag иливыдатьложныйотказнауменьшениеbelowfold. Сохранятьfocusedcontrol/названиябезтехническихID.
6. Theme/style/color/transparency/fastmode DOMcontractизfoundation: data-theme,color-scheme,accent,interface-style,wallpaper,performance,motion,--os-glass-opacity. НеимпортироватьfoundationThemeService APIкоторогоещёнетнаэтомbase; применятьCSStokensсopaquefallback инеизменятьглобальнуюThemeService/styles. Никакоговложенногоblur/бесконечныхanimations/скрытогоpolling.
7. UK/RU/EN, standaloneOnPush/signals/inject/typedformsбезany. Unitlayout/permission/data/lifecycle иAngularcomponenttests; добавитьCIE2Eстраницу/flowsбезослабленияsnapshots/axe (rootactualCIcapture). Полныйbuild/visualCI, локальноtargetedtests/lintтолько.

## Стан

- Время: 2026-10-30, Asia/Saigon.
- Worktree: `D:/Projects/HRM/worktrees/browser-os-desktop`, branch `feat/browser-os-desktop`; verified base/source start `899d4dab4e4ebd9735d785142a0b0fa47b0ac76a` (`origin/main`).
- Scope: standalone lazy `/desktop`, real role/module-aware app shortcuts, API-backed summary widgets, per-user presentation layout, targeted tests, module docs/worklog, and parity route/flow coverage. `/` remains unchanged; no round-two window integration or global theme edits.
- Completed: API/file ownership contract sent to root; installed the exact frontend lockfile; added strict layout model/store, request lifecycle/data privacy, launcher adapter, access-filtered registry, desktop UI/route/i18n, focused tests, docs, and E2E entry/flow.
- Verified: focused Angular tests passed (5 files, 20 tests); targeted Angular lint passed; E2E files passed ESLint and `tsc -p e2e/tsconfig.json`; UK/RU/EN locale JSON parsed. No app server, DB, full suite, full build, visual snapshot review, E2E run, Git operation, PR, or merge.
- Next: root draft CI/SOL review and actual rendered review/capture for the new `/desktop` parity baseline; snapshots were not auto-generated or blessed.

CI support: reused the exact Luna-authored inventory attachment/screenshot instrumentation from foundation fe226e00 in parity.pw.ts; missing/mismatched baselines still fail, no axe thresholds or fixtures changed. Actual desktop baseline will be reviewed from CI.
