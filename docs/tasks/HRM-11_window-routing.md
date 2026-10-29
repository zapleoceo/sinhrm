# HRM-11 — реальная оконная маршрутизация

## Контекст

Владелец поручил весь OS редизайн, standalone v5 больше не является результатом внедрения. Этот отдельный round2 развивается параллельно foundation на независимой ветке отmain. Foundation ещё проверяется. Архитектурный preflight: D:/Projects/HRM/worktrees/browser-os-system/docs/ai-ui/tasks/system-os-redesign/window-architecture-review.md (SOL,publicAngular22 source+DI проверка).

## План и инварианты

1. Реальные RouterOutlet primary+2 named slots os1/os2, одинаковые исходные route definitions без дублирования страниц/guards, lazy creation. Public/login/jobs не в workspace.
2. moduleGuard ветки actualroute metadata/canonicalbranchpath, сохранять role/auth/server security и canDeactivate. Проверки disabledauxmodule приprimarycore, deepauxURL, paraminputs, closeguardcancel, identity/roleinvalidate.
3. Типизированные WindowContext/WindowNavigation + opt-in scoped publicRouterfacade, rootRouter остаётся engine. Настоящие UrlTree, никаких private DetachedRouteHandle/ComponentPortal fakepages; href/Ctrl-click/absolute/relative/navigation cancellation тестируются.
4. WindowQueryState: primaryстарыеqueriesнеизменны, auxprefixencoded, sharedTableUrlState/ClientTable+auditeddirectreaders используютadapter. Независимые2таблицы, delayedwrites/Back, реальныйActivatedRoute+inputbinding остаются.
5. Первый enabledpilot Tasks/People list (конечные запросы) +boundedwrappers; остальным временноprimaryflow доаудита. НЕ объявлятьэтимвесьредизайнзавершённым. Развиватьсовместимостьпостепенно, preserveforms/polling/lifecycle.
6. Настоящие окна: header/title/minimize/focus/maximize/restore/close; минимизациясохраняетrouteinstance, закрытиеRouterпроводитguardиdestroy. Геометриякосметическая, никакиеформы/кадровыеданныенепишутсявlocalStorage. Mobileодинactiveviewсinertinactive.
7. UnitRouter/TestBedintegrationтесты безlocalappserver; fullbuild/e2e/negativepermissionsвCI. SOL независимыйreviewcombinedпередmerge. Dependenciespresentinotherworktree mayreadbutdonotmodify; installlockedfrontendnpmdepsinsideownworktreeifneeded.

## Стан

2026-10-30 Asia/Saigon: ROUND2 implementation and SOL correction batches in `D:/Projects/HRM/worktrees/browser-os-windows`, branch `feat/browser-os-windows` from `899d4dab`, separate from foundation. Fixed canonical/matrix query merging and preserve behavior, explicit primary focus state, reopening a minimized page, auth identity/effective-role/active-role invalidation plus module checks, window-aware pending table-edit cancellation, and mobile inactive-window inert/hiding. Root `Router` still executes navigation and guards. Public/login/jobs and unaudited routes remain primary; only Tasks/People lists are allowlisted. Evidence: focused frontend tests 79/79 across six specs, Angular lint passes, E2E TypeScript and ESLint pass, and repo docs/tests gate helpers pass when supplied the uncommitted changed-scope list. Angular's `SpyLocation.back()` does not dispatch Router navigation in this harness; unit test asserts replay of the captured full prior URL and is not claimed as browser-history proof. Playwright covers actual desktop `page.goBack/goForward`, two live pages, minimize/restore, and mobile focus boundaries at 390/375px; browser execution is pending CI. Next: root/SOL final review and CI. No merge/deploy authorization.
