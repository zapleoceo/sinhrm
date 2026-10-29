# /implement-ui — полный редизайн SinHRM

## Авторизация и цель

29.10.2026 владелец поручил внедрить редизайн всей системы по согласованному направлению v5: браузерная ОС, виджеты с добавлением/перемещением/размером, каждый модуль в своём окне, Apple-inspired стекло, настраиваемые тема/цвет/стиль/прозрачность и быстродействие. Это переход от mockup к настоящему Angular приложению; синтетические данные макета в production пути не переносятся. Авторизация на implementation/branches/PR/CI есть; automatic merge/deploy не подразумеваются.

## Источники

Reference: D:/Projects/HRM/tmp/browser-os-design/v5/SinHRM-OS-v5.html; source/reports в D:/Projects/HRM/worktrees/browser-os-design/docs/{design/browser-os,ai-ui/tasks/browser-os-shell}. Jira KB search projectHRM redesign/interface/theme/desktop: HRM-11 «Интерфейс Маршрут...» To do, assigned владельцу; HRM-84 относится к правам и не к редизайну. Старая тема Маршрут уступает последнему прямому указанию владельца. Jira не редактируется без отдельной задачи на запись. Источник business behavior — текущие реальные страницы, routes/guards/API/i18n, не макет.

## Инварианты

Luna implementation; SOL независимая архитектурная/кодовая/visual проверка. Одна фича — отдельная ветка/PR. Не смешивать с чужими рабочими копиями: основной D:/Projects/sinhrm detached245a20fc содержит локальные .angular/AGENTS.md, не изменять его. Все новые артефакты внутри D:/Projects/HRM. Не запускать локальный app/DB/full suite; targeted lint/tests локально, полный build/tests/ui-parity в CI; rendered evidence из CI/разрешённого preview.

Сохраняются auth/module/role checks, URL/Back/deep links, query filters, forms/unsaved state, API, i18nUK/RU/EN, доступность и controls inventory. Нельзя заявлять все модули оконными из UI рамки над единственным outlet; не использовать приватные DetachedRouteHandle internals или dynamiccomponents без настоящих guards/routeDI. Большое внедрение вести раундами, состояние и незавершённое фиксировать.

## Раунды

1. OS-01 Foundation: typed preferences/theme compatibility, real appearance settings, shared material/design tokens всех страниц; production-safe пока primary routing прежний. Risk3 sharedsystem.
2. OS-02 Routing/window bridge: route-derived module policy, window-scoped query/navigation, publicAngular namedoutlets, lifecycle/unsaved guard tests; затем реальные независимые окна.
3. OS-03 Desktop: разрешённые модули группами, widgets/layout/resize/per-user metadata, Dock и мобильный switcher; actual APIs only, bounded queries.
4. OS-04 All-screen completion: inventory каждого реального route/dialog/table/form/publicscreen, fixstyleexceptions, UK/RU/EN и viewportmatrix, visual/functional/negative permissions.
5. OS-05 Final integrated review: совместимость PR, CI/render evidence, измерения latency/frame/memory, честные ограничения, review до разрешённого merge/deploy.

## State

Worktree D:/Projects/HRM/worktrees/browser-os-system; branchfeat/browser-os-foundation fromorigin/main899d4dab4e4ebd9735d785142a0b0fa47b0ac76a (freshfetch). Prototype files remain in their separate worktree. Discovery Luna, architectural preflight SOL. No production changes yet.
