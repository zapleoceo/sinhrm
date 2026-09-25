# Модуль Overview — главная страница (дашборд)

## Что это и зачем
Первый экран после входа отвечает на вопрос «что мне сделать сегодня»: сколько кандидатов в работе, кто «завис» без
контакта, что лежит неразобранным во «Вхідних», какие задачи на сегодня, как выглядит воронка и сколько было касаний за
неделю. Всё — в пределах филиалов пользователя. Спокойные карточки без графиков-«ёлок»: числа и полоски.

Прежняя стартовая страница «Стан системи» (проверка API и базы) переехала на `/status` — пункт меню для суперадмина и админа.

## Как пользоваться
Меню → «Огляд» (или логотип). Сверху четыре счётчика — клик ведёт в раздел:
«активних кандидатів» → Кандидати, «без контакту 3+ дні» → Кандидати, «нерозібраних у Вхідних» → Вхідні,
«нових сьогодні» → Вакансії. Ненулевые «зависшие» и «Вхідні» подсвечены.
Ниже: **Мої задачі на сьогодні** (включая просроченные; галочка закрывает задачу — [scripts.md](scripts.md)),
**Без контакту 3+ дні** (10 самых давних, клик — карточка кандидата), **Воронка активних кандидатів** (по этапам всех
вакансий) и **Касання за 7 днів** по каналам. Кнопка «Оновити» перечитывает данные.

## Как устроено
Бэкенд — `backend/app/Modules/Overview`: `GET /api/dashboard` (`auth:sanctum` + активный пользователь, все роли).
`Http/Controllers/DashboardController` → `Services/DashboardService` → `Contracts/DashboardRepository`
(`Repositories/QueryDashboardRepository`, SQL совместим с Postgres и SQLite). Модуль только читает данные Recruiting и
Scripts; своих таблиц нет.

| Поле ответа `data` | Что | Откуда |
|---|---|---|
| `counts.active` | активные заявки | `applications.status = active` |
| `counts.stale` | из них без реального касания ≥ 3 дней | `coalesce(last_touch_at, created_at)`, порог `StalenessService::DEFAULT_DAYS` |
| `counts.unmatched_inbox` | касания без кандидата | как видимость «Вхідних»: автор — пользователь или линия его филиала |
| `counts.new_today` | заявки, созданные с начала сегодняшнего дня | `applications.created_at` |
| `my_tasks` | `{total, overdue, items[≤20]}` мои открытые задачи до конца дня | `Scripts\Services\TaskService` (`mine`, `due=today`) |
| `stale[≤10]` | `{application_id, candidate, vacancy, stage, last_activity_at, days}` | `Recruiting\Contracts\ApplicationRepository::stale` |
| `funnel` | активные заявки по этапу (`stage_name, stage_kind, position, count`) | этапы разных воронок с одинаковым названием и позицией суммируются |
| `touches` | `{days: 7, by_channel[{channel, count}]}` | касания кроме `system`, видимость как у отчёта «касания» |
| `warnings` | `[{code, level, params?, link?}]` — предупреждения других модулей, баннер над счётчиками | контракт `Contracts/DashboardNotices` (тег): модуль регистрирует `$this->app->tag([...], DashboardNotices::class)`. Сейчас — `GoogleWorkspace\Services\GoogleDashboardNotices`: суперадмину `google_reconnect_required {service}`, если Google отозвал доступ ([google-workspace.md](google-workspace.md)) |

Ограничение по филиалам — `Recruiting\Services\RecruitingScope` (superadmin/admin видят всё, остальные — вакансии своих
филиалов; пользователь без филиалов видит нули).

Фронтенд — `frontend/src/app/features/overview`: `dashboard.page.*` (маршрут `''`; баннеры `warnings` со ссылкой — строки
`overview.warnings.<code>`), `overview.store.ts` (плитки, ширины
полосок), `overview.service.ts`, `overview.model.ts` (`statTiles` — куда ведёт каждый счётчик). Виджет задач —
`features/scripts/tasks/tasks-widget.ts`. Строки — `overview.*` в `public/i18n/{uk,ru,en}.json`.

## Как проверить
`tests/Feature/Overview/DashboardApiTest` — гость 401; рекрутер видит только свой филиал (счётчики, зависшие, «Вхідні»,
задачи, воронка, касания по каналам); админ — всё; viewer без филиалов — нули. Предупреждения —
`tests/Feature/GoogleWorkspace/GoogleTokenTest` (суперадмин видит `google_reconnect_required`, рекрутер — пустой список).
Фронт: `overview.spec.ts`.
Вручную: войти → «Огляд» → счётчики совпадают с «Кандидати»/«Вхідні», клик по зависшему открывает карточку.
