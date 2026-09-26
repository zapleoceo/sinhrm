# Модуль Core

## Что это и зачем
Общий фундамент: проверка, что система жива и видит базу данных, и базовый механизм подключения модулей.

## Как пользоваться
Суперадмин и админ: меню → «Стан системи» (`/status`) показывает состояние API и его зависимостей (раньше это была
стартовая страница; теперь стартовая — дашборд, [overview.md](overview.md)). Сама проверка `GET /api/health` открыта без входа.
Вкладка браузера подписана «SinHRM · <раздел>» на языке интерфейса.

## Как устроено
- `GET /api/health` → `{"version": "...", "ok": true, "checks": {"database": {"ok": true}}}`; код 200 или 503.
- Каждая зависимость — класс, реализующий `Contracts\HealthCheck`; модули добавляют свои проверки через
  `$app->tag([...], HealthCheck::class)`. Ошибка проверки не раскрывает детали подключения — только класс исключения.
- `Support\ModuleServiceProvider` — базовый провайдер модуля: подключает миграции из `Database/Migrations` и маршруты под
  `/api/<prefix>`: `routes.php` — группа `api` (JSON; Sanctum делает запросы SPA сессионными), `routes.web.php` — группа
  `web` (сессия есть всегда) для браузерных редиректов от внешних сервисов, например OAuth-callback Google.
- Фронт: `core/api/health.service.ts` (ошибка сети → отчёт «unreachable»), экран `features/core/status.page.ts`
  (маршрут `/status` оболочки, `roleGuard('superadmin', 'admin')`).

### Фронтенд: общие сервисы `frontend/src/app/core`
| Файл | Что делает |
|---|---|
| `auth/auth.service.ts` | состояние сессии (signals `user`, `loading`); `GET /api/auth/me` один раз при старте, 401 → гость; `logout()` |
| `auth/auth.model.ts` | типы и списки ролей (`USER_ROLES`, `INVITABLE_ROLES`, `HR_STAFF_ROLES`, `isHrStaff`) — зеркало `UserRole` бэкенда |
| `auth/auth.guards.ts` | `authGuard` (гость → `/login`), `roleGuard(...roles)` (нет ни одной из ролей → `/`; например `roleGuard('superadmin', 'admin')`), `guestGuard` (для `/login`) |
| `auth/auth.model.ts` | типы и списки ролей/статусов/языков — зеркало enum бэкенда |
| `http/csrf.interceptor.ts` | перед первым POST/PATCH/DELETE берёт `GET /sanctum/csrf-cookie`, ставит `X-XSRF-TOKEN`; на 419 — повтор один раз |
| `i18n/*` | Transloco: `public/i18n/{uk,ru,en}.json`, язык пользователя (сервер) или гостя (localStorage) |
| `i18n/translated-title.strategy.ts` | `TitleStrategy`: `title` маршрута — ключ i18n (`titles.*`), во вкладке «SinHRM · Кандидати»; при смене языка заголовок переводится заново (`selectTranslate`). Без `title` — просто «SinHRM» (он же в `index.html`) |
| `theme/theme.service.ts` | светлая/тёмная тема: по умолчанию как в ОС, выбор хранится в localStorage (`<html data-theme>`) |
| `storage/safe-storage.ts` | localStorage без исключений (приватный режим, запрет cookies) |
| `date/iso-date.ts` | даты без сдвига часового пояса: `toIsoDate(Date)` → `'YYYY-MM-DD'` по локальному календарю (не через `toISOString()`), `toIsoDateOrNull`, `fromIsoDate('YYYY-MM-DD')` → локальная полночь (переполнение вроде 31.02 → `null`), `toIsoLocalDateTime`/`fromIsoLocalDateTime` (`YYYY-MM-DDTHH:mm`, бывший формат `datetime-local`), `combineDateAndTime`, `toTimeString`/`fromTimeString` (`HH:mm`), `today()`. Контракт API не менялся: на бэкенд уходят те же строки |
| `date/app-date-adapter.ts`, `date/provide-app-dates.ts` | `provideAppDates()` в `app.config.ts`: `AppDateAdapter` (наследник `NativeDateAdapter`, без новых зависимостей) — неделя с понедельника, в поле ввода всегда `дд.мм.рррр` (принимает также `д.м.рррр`, `дд/мм/рррр`, ISO), ISO-строки читаются как локальная дата; `APP_DATE_FORMATS` (время `HH:mm`, 24 ч). Локаль (`uk-UA`/`ru-RU`/`en-GB`) переключает `LanguageService` вместе с языком интерфейса |
| `date/datepicker-intl.ts` | подписи кнопок календаря (`common.datepicker.*`) для `MatDatepickerIntl`, обновляются при смене языка; грузится динамическим `import()` — статический импорт тянул весь datepicker в стартовый бандл (+~340 kB) |
| `ui/logo.ts` | `<app-logo [variant]="'mark'\|'full'" [mono]>` — логотип SinHRM: квадрат с вырезанной «S» (цвет `--app-brand`) + словесный знак Onest; `role=img`, `aria-label="SinHRM"`. Используется на странице входа и в шапке сайдбара; из него же `public/favicon.svg` и растровые иконки (`scripts/gen-icons.mjs`), см. [design-direction.md](../architecture/design-direction.md) §8 |
| `ui/channel-icon.ts`, `ui/channel-icons.ts` | `<app-channel-icon [key] [label]>` — иконка Font Awesome Free канала / источника / интеграции в цвете бренда: telegram, whatsapp, viber, linkedin, meta_ads, google/gmail/sheets/calendar — брендовые; work_ua/robota_ua/djinni/dou — портфель с буквой (в FA Free их нет); телефония — `faPhoneVolume`, AI — робот/мозг, Deepgram — волна; неизвестный ключ — нейтральный знак вопроса. С `label` — `role=img` + `aria-label` + подсказка, без — декоративная (`aria-hidden`), когда название написано рядом. Используется в карточке кандидата (контакты, чипы источника, фильтры и лента), композере, «Вхідних», дашборде, отчётах, каналах залучення, интеграциях, Google-подключении, странице расширения |
| `errors/error-reporter.service.ts`, `errors/global-error-handler.ts`, `errors/server-error.interceptor.ts` | журнал ошибок браузера: `GlobalErrorHandler` (исключения JS) и `serverErrorInterceptor` (ответы 5xx) отправляют `POST /api/errors/client` — только вошедший пользователь, без повторов, не больше 20 за загрузку; [architecture/observability.md](../architecture/observability.md) |
| `http/api-error.ts` | `apiErrorKey(error, prefix, codes)` — i18n-ключ ошибки API: известный `{code}` → `<prefix>.errors.<code>`, иначе по статусу (`forbidden` 403, `not_found` 404, `validation` 422, `rate_limited` 429), иначе `common.error`; `saveBlob(blob, name)` — скачать ответ-Blob (CSV). Используют Desk, Safe Speak, Knowledge, Assets, Reports |

Все строки интерфейса — через Transloco (`'ключ' | transloco`); новый текст добавляется во все три файла `public/i18n`.

## Как проверить
Тесты: `iso-date.spec.ts`, `app-date-adapter.spec.ts`, `datepicker-intl.spec.ts`, `channel-icon.spec.ts`, `tests/Feature/Core/HealthTest.php`, `tests/Feature/Core/OpsJobsTest.php`, `tests/Feature/Core/SecurityHeadersTest.php`, `error-reporter.spec.ts`, `tests/Unit/Core/HealthServiceTest.php`, `health.service.spec.ts`,
`auth.service.spec.ts`, `auth.guards.spec.ts`, `csrf.interceptor.spec.ts`, `language.service.spec.ts`, `translated-title.strategy.spec.ts`.
Вручную: `curl -i https://sinhrm.vercel.app/api/health`.

## Подключение к Neon из Vercel
Библиотека libpq в рантайме vercel-php не поддерживает SNI, и Neon отвечает «Endpoint ID is not specified».
`Support\NeonConnectionConfig` (применяется в `CoreServiceProvider::register`) разбирает `DATABASE_URL` и передаёт
id эндпоинта внутри пароля (`endpoint=<id>;<пароль>`) — документированный обход Neon. Применяется только если libpq < 14
(`PGSQL_LIBPQ_VERSION`): современный клиент (CI) шлёт SNI, и тогда префикс ломает пароль. Для не-Neon URL ничего не меняется.
Проверено: до исправления `/api/health` → 503 с этой ошибкой, после → 200.

## Заголовки безопасности
`Http\Middleware\SecurityHeaders` (подключён в `bootstrap/app.php`) ставит на каждый ответ API CSP `default-src 'none'`,
`X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS; `/api/docs` — без CSP. Для SPA те же
заголовки задаёт `frontend/vercel.json`. Подробно и почему так — [architecture/observability.md](../architecture/observability.md).

## Точка входа Vercel
`backend/api/index.php` подменяет `SCRIPT_NAME` на `/index.php`: иначе Laravel считает `/api` базовым путём и
`/api/health` превращается в `/health` (404). API-only: страниц нет; единственные маршруты группы `web` — `/api/auth/google/*` (модуль Auth); на `sinhrm-api.vercel.app/` — 404. Публичный `sinhrm.vercel.app/` — это фронтенд.
Контракт проверки здоровья — `/api/health` (зависимости); `/up` — встроенная проверка Laravel «процесс жив», без БД.

## Служебные эндпоинты `/api/ops/*`
**Зачем.** Vercel не отдаёт защищённый `DATABASE_URL` наружу (`vercel pull` получает маску), поэтому миграции
запускает сам API — доступ к БД не покидает Vercel.

| Эндпоинт | Что делает |
|---|---|
| `POST /api/ops/migrate` | применяет новые миграции |
| `POST /api/ops/migrate?fresh=1` | пересоздаёт БД и заполняет синтетикой; **в production запрещено (403)** |
| `POST /api/ops/jobs/run` | один проход всех фоновых задач (cron каждые 30 мин, `.github/workflows/cron.yml`) → `{ok, jobs: {<name>: {ok, …счётчики}}}` |

Защита: заголовок `X-Ops-Secret` = `OPS_SECRET` (Vercel env + GitHub secret), сравнение `hash_equals`;
секрет не задан → 404 (эндпоинта «нет»), неверный → 401; после 10 неверных попыток в минуту с одного IP → 429. Считаются **только неудачные** попытки:
запрос с верным секретом не трогает кэш, поэтому миграции работают и на пустой БД (таблицы `cache` ещё нет).
В публичный лог Actions пишется только «migrations: ok/FAILED». Время выполнения ограничено `maxDuration` 60 с. Код: `Http/Middleware/RequireOpsSecret`,
`Http/Controllers/OpsMigrateController`, `Contracts/MigrationRunner` → `Services/ArtisanMigrationRunner`.
Тест: `tests/Feature/Core/OpsMigrateTest.php`. `APP_ENV` задаётся переменной Vercel: `production` / `preview`.

### Рабочие дни (`Contracts/WorkingCalendar`)
Один общий календарь рабочих дней для сроков согласований (SLA) всех модулей: рабочий день — пн–пт, если это не
праздник из TimeOff (общий или праздник филиала). По умолчанию срок согласования — 2 рабочих дня
(`WorkingCalendar::DEFAULT_SLA_DAYS`). Пример: пятница 10:00 + 2 рабочих дня → вторник 10:00.
Технически: `addWorkingDays(Carbon $from, int $days, ?int $branchId)` (время суток сохраняется, старт в выходной
считается со следующего рабочего дня) и `isWorkingDay(Carbon $day, ?int $branchId)`. Реализация —
`TimeOff\Services\HolidayWorkingCalendar` (привязка в `TimeOffServiceProvider`), модули зависят только от контракта.
Сейчас используется в HiringRequests ([hiring-requests.md](hiring-requests.md)).

### Защита от вычитания между выпусками (`Support/MembershipDifferencing`)
Чистая функция без БД для анонимных агрегатов, которые публикуются повторно (волны опросов, циклы 360): группа
в новом выпуске показывается, только если её состав совпадает с каждым прошлым **показанным** выпуском той же
группы или отличается от него хотя бы на минимум людей. Иначе «новый итог минус старый» выдал бы ответ одного-двух
человек. `symmetricDifference(a, b)` — сколько людей пришло + ушло; `allowed(d, min)` — `d = 0` или `d ≥ min`;
`visibility(releases)` — видимость каждой группы в каждом выпуске (скрытый выпуск базой не считается). Участники —
непрозрачные строки (HMAC или id). Используют Pulse ([pulse.md](pulse.md)) и Perform ([perform.md](perform.md)).
Тест: `tests/Unit/Core/MembershipDifferencingTest.php`.

### Фоновые задачи (`Contracts/ScheduledJob`)
У vercel-php нет воркеров и постоянных процессов, а cron Vercel Hobby — раз в сутки. Поэтому GitHub Actions
(`cron.yml`) каждые 30 минут дёргает `POST /api/ops/jobs/run`. Модуль регистрирует задачу так:
`$this->app->tag([MyJob::class], ScheduledJob::class)`; интерфейс — `name()` и `run(Carbon $now): array` (счётчики, без
персональных данных). Задача **обязана быть идемпотентной** (повтор или наложение запусков ничего не дублируют).
`Http/Controllers/OpsJobsController` запускает все задачи по очереди; упавшая не останавливает остальные, ответ тогда
`ok: false` (шаг cron краснеет), исключение уходит в `report()`. Сейчас зарегистрированы, среди прочих, `timeoff.accrue` (начисление отпусков, [timeoff.md](timeoff.md)), `followups` (модуль Scripts —
задачи-напоминания, [scripts.md](scripts.md)) и `workflows.tick` (шаги воркфлоу и запуск по окончании испытательного срока,
[workflows.md](workflows.md)).

### Счётчики в меню (`Contracts/NavBadgeProvider`)
`GET /api/nav/badges` (вход обязателен) отдаёт числа для значков в меню **только текущего пользователя**, например
`{"data": {"tasks": 1, "inbox": 4}}`. Каждый модуль сам считает свои пункты: класс с `badges(User $user): array`,
регистрация `$this->app->tag([MyNavBadges::class], NavBadgeProvider::class)`. Правило: число берётся тем же сервисом и
той же областью видимости, что и список на странице, поэтому совпадает с тем, что человек увидит, открыв пункт (это проверяют
тесты модулей: значок = длина списка). Нет права на пункт — провайдер не возвращает ключ (значка нет вовсе); 0 — значок скрыт.
`Services/NavBadgeService` собирает ответы всех провайдеров и держит их в кэше 30 секунд на пользователя (ключ
`nav-badges:<id>:<хэш ролей>` — смена роли видна сразу), чтобы опрос меню раз в минуту не нагружал базу. Ключи: `tasks`, `inbox`, `hiring_inbox`,
`timeoff_approvals`, `time_approvals`, `my_documents`, `surveys`, `desk_mine`, `desk_queue`, `safe_speak`, `mail_unknown`
(что считает каждый — в документации модуля).

## Логи
На Vercel логи пишутся в stderr в формате JSON без стектрейса (`LOG_STDERR_FORMATTER=JsonFormatter`, уровень `warning`):
Vercel обрезает длинные сообщения, и текст ошибки иначе терялся за трассировкой.
