# Модуль Shell (фронтенд)

## Что это и зачем
«Рамка» приложения после входа: меню слева, панель сверху с переключателем темы и меню пользователя.
Все рабочие экраны открываются внутри неё. Стиль — спокойный и без лишнего: Angular Material 3, один акцентный цвет,
просторные отступы ([design-direction.md](../architecture/design-direction.md)).

## Как пользоваться
- Меню слева: «Огляд» (дашборд, [overview.md](overview.md)); раздел «Рекрутинг» (всем ролям): «Кандидати», «Вакансії», «Вхідні», «Звіти» ([recruiting.md](recruiting.md)); раздел «Люди» (всем ролям): «Співробітники», «Оргструктура» ([people.md](people.md)), «Мої відсутності», «Календар команди», «Погодження» ([timeoff.md](timeoff.md)); раздел «Продуктивність» (всем ролям): «Цілі (OKR)», «Зустрічі 1:1», «Фідбек», «Мої оцінювання» ([perform.md](perform.md)), «Опитування», «Настрій» ([pulse.md](pulse.md)); раздел «Адміністрування»: «Користувачі», «Інтеграції», «Пошта» ([mail-agent.md](mail-agent.md)) и «Імпорт з Google Sheets» ([google-workspace.md](google-workspace.md)) — только суперадмину, «Скрипти» ([scripts.md](scripts.md)), «Налаштування відсутностей» ([timeoff.md](timeoff.md)), «Довідники», «Цикли оцінювання», «Опитування» (конструктор) и «Стан системи» — суперадмину и админу.
- Вкладка браузера: «SinHRM · <раздел>» на языке интерфейса ([core.md](core.md), `TranslatedTitleStrategy`).
- Глобального поиска и горячих клавиш нет: кнопка «Пошук», палитра быстрого перехода и Ctrl/⌘+K убраны по решению владельца
  (2026-09-26). Искать — полями поиска на страницах (кандидаты, пользователи, справочники…). Серверный поиск API не менялся.
- Справа вверху: кнопка светлой/тёмной темы; меню пользователя (аватар, имя, e-mail, язык UK/RU/EN, «Мій профіль» → `/me` ([people.md](people.md)), «Розширення браузера» → `/settings/extension` ([extension.md](extension.md)), «Вийти»).
- Тема по умолчанию как в системе (светлая/тёмная), ручной выбор запоминается в этом браузере.
- На узком экране меню слева становится строкой сверху.

## Как устроено
- `features/shell/shell.layout.ts|html|scss` — layout (CSS grid), маршруты-дети рендерятся в `<router-outlet>`.
- `features/shell/language-switcher.ts` — переключатель языка (также на странице входа).
- Маршруты (`app.routes.ts`): `/login` (гости, `guestGuard`), `/` → оболочка (`authGuard`) с детьми
  `''` — дашборд (Overview), `vacancies`, `vacancies/:id`, `candidates`, `candidates/:id`, `inbox`, `reports` (модуль Recruiting, все роли), `people`, `people/org-chart`, `people/:id`, `me`, `timeoff`, `timeoff/calendar`, `timeoff/approvals` (People/TimeOff, все роли), `tasks` («Мої задачі», все роли), `me/documents` («Мої документи»), `workflows/runs` (доска воркфлоу; данные режет API), `perform/one-on-ones`, `perform/objectives`, `perform/feedback`, `perform/reviews`, `pulse`, `pulse/mood`, `pulse/waves/:id`, `pulse/waves/:id/results` (Perform/Pulse, все роли; данные режет API), `admin/perform/reviews`, `admin/pulse` — `roleGuard('superadmin', 'admin')`, `admin/workflows`, `admin/workflows/:id`, `admin/documents/templates` — `roleGuard('superadmin', 'admin')`, `settings/extension` (токен расширения, все роли), `desk`, `desk/cases/:id`, `safe-speak`, `knowledge`, `knowledge/:id`, `reports/catalog`, `reports/catalog/:key`, `reports/builder` (Desk/Safe Speak/Knowledge/Reports, все роли; данные режет API), `desk/queue`, `safe-speak/inbox`, `admin/knowledge/:id`, `admin/assets` — `roleGuard('superadmin', 'admin')` (для входящих Safe Speak API ещё требует флаг обработчика), `hiring-requests`, `hiring-requests/inbox`, `hiring-requests/new`, `hiring-requests/:id`, `hiring-requests/:id/edit` (заявки на подбор, все роли; права — API), `time`, `time/approvals`, `time/team` (табели, все роли; данные режет API), `admin/hiring-requests`, `admin/acquisition-channels`, `admin/time` — `roleGuard('superadmin', 'admin')`, `admin/users` и `admin/integrations` — `roleGuard('superadmin')`, `admin/directory`, `admin/scripts`, `admin/scripts/:id`, `admin/timeoff`, `status` — `roleGuard('superadmin', 'admin')`. Экраны загружаются лениво. У каждого маршрута `title` — ключ `titles.*`.
- Тема: `core/theme/theme.service.ts` ставит `<html data-theme="light|dark">`; `styles.scss` задаёт `color-scheme`,
  тема Material собрана с `theme-type: color-scheme`, поэтому все токены `--mat-sys-*` переключаются сами.
  Токены приложения (`--app-gap`, `--app-radius`, `--app-border`, `--app-success`, `--app-danger`) — там же.
- Глобальных обработчиков клавиатуры (`document:keydown`) в оболочке нет.
  Контент ограничен шириной 96rem (доска и split view шире прежних 72rem).
- Меню: раздел «Сервіси» (Мої звернення, База знань, Safe Speak, Каталог звітів, Конструктор звітів) для всех;
  в «Адміністрування» — Черга звернень, Вхідні Safe Speak, Налаштування заявок, Канали залучення, Графіки роботи, Активи.
  В «Рекрутинг» — «Заявки на підбір»; в «Люди» — «Мій табель», «Погодження табелів», «Табелі команди». Пункт «Звіти» рекрутинга (`/reports`) подсвечивается
  только на своей странице (`exact`), чтобы не гореть на `/reports/catalog`.
- Все подписи — ключи Transloco `shell.*` в `public/i18n/{uk,ru,en}.json`.
- Общее для всех экранов (подключается в `app.config.ts`, подробно — [core.md](core.md)): даты — только выпадающий календарь
  Angular Material (`provideAppDates()`: неделя с понедельника, формат дд.мм.рррр, названия месяцев на языке интерфейса);
  значки каналов/источников/интеграций — `app-channel-icon` (Font Awesome Free), остальные действия — Material Symbols.

## Как проверить
`npx ng test --watch=false` (guards, AuthService, язык), `npx ng build`.
Вручную: войти → переключить тему и язык → обновить страницу (выбор сохранился) → «Вийти» ведёт на `/login`.
Открыть любой календарь (например, «Мої відсутності») → неделя с понедельника, месяцы на языке интерфейса; Ctrl/⌘+K ничего не делает.
HR-страницы (`admin/workflows*`, `admin/perform/reviews`, `admin/pulse`, `admin/documents/templates`, `admin/hiring-requests`, `admin/time`, `desk/queue`, `safe-speak/inbox`, `admin/knowledge/:id`, `admin/assets`, `admin/timeoff`) охраняет `roleGuard(...HR_STAFF_ROLES)` — открыты и `hr_manager`; в меню их показывает `isHr()`. `admin/acquisition-channels`, `admin/scripts`, `admin/directory`, `status` остаются за `superadmin`/`admin` (`isAdmin()`).

Пользователь не суперадмин не видит пункт «Користувачі», а прямой переход на `/admin/users` возвращает на `/`.
