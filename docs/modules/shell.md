# Модуль Shell (фронтенд)

## Что это и зачем
«Рамка» приложения после входа: меню слева, панель сверху с переключателем темы и меню пользователя.
Все рабочие экраны открываются внутри неё. Стиль — спокойный и без лишнего: Angular Material 3, один акцентный цвет,
просторные отступы ([design-direction.md](../architecture/design-direction.md)).

## Как пользоваться
- Меню слева: «Огляд» (дашборд, [overview.md](overview.md)); раздел «Рекрутинг» (всем ролям): «Кандидати», «Вакансії», «Вхідні», «Звіти» ([recruiting.md](recruiting.md)); раздел «Адміністрування»: «Користувачі», «Інтеграції», «Пошта» ([mail-agent.md](mail-agent.md)) и «Імпорт з Google Sheets» ([google-workspace.md](google-workspace.md)) — только суперадмину, «Скрипти» ([scripts.md](scripts.md)), «Довідники» и «Стан системи» — суперадмину и админу.
- Вкладка браузера: «SinHRM · <раздел>» на языке интерфейса ([core.md](core.md), `TranslatedTitleStrategy`).
- Слева вверху кнопка «Пошук» и **Ctrl/⌘+K** в любом месте — палитра быстрого перехода к кандидату, вакансии или разделу (↑/↓, Enter, Esc).
- Справа вверху: кнопка светлой/тёмной темы; меню пользователя (аватар, имя, e-mail, язык UK/RU/EN, «Вийти»).
- Тема по умолчанию как в системе (светлая/тёмная), ручной выбор запоминается в этом браузере.
- На узком экране меню слева становится строкой сверху.

## Как устроено
- `features/shell/shell.layout.ts|html|scss` — layout (CSS grid), маршруты-дети рендерятся в `<router-outlet>`.
- `features/shell/language-switcher.ts` — переключатель языка (также на странице входа).
- Маршруты (`app.routes.ts`): `/login` (гости, `guestGuard`), `/` → оболочка (`authGuard`) с детьми
  `''` — дашборд (Overview), `vacancies`, `vacancies/:id`, `candidates`, `candidates/:id`, `inbox`, `reports` (модуль Recruiting, все роли), `admin/users` и `admin/integrations` — `roleGuard('superadmin')`, `admin/directory`, `admin/scripts`, `admin/scripts/:id`, `status` — `roleGuard('superadmin', 'admin')`. Экраны загружаются лениво. У каждого маршрута `title` — ключ `titles.*`.
- Тема: `core/theme/theme.service.ts` ставит `<html data-theme="light|dark">`; `styles.scss` задаёт `color-scheme`,
  тема Material собрана с `theme-type: color-scheme`, поэтому все токены `--mat-sys-*` переключаются сами.
  Токены приложения (`--app-gap`, `--app-radius`, `--app-border`, `--app-success`, `--app-danger`) — там же.
- Палитра: `features/recruiting/palette/command-palette.service.ts` (CDK overlay), горячая клавиша — `host (document:keydown)` в `shell.layout.ts`.
  Контент ограничен шириной 96rem (доска и split view шире прежних 72rem).
- Все подписи — ключи Transloco `shell.*` в `public/i18n/{uk,ru,en}.json`.

## Как проверить
`npx ng test --watch=false` (guards, AuthService, язык), `npx ng build`.
Вручную: войти → переключить тему и язык → обновить страницу (выбор сохранился) → «Вийти» ведёт на `/login`.
Пользователь не суперадмин не видит пункт «Користувачі», а прямой переход на `/admin/users` возвращает на `/`.
