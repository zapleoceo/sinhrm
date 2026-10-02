# Модуль Core

## Что это и зачем
Общий фундамент: проверка, что система жива и видит базу данных, и базовый механизм подключения модулей.

## Как пользоваться
Суперадмин и админ: меню → «Стан системи» (`/status`) показывает состояние API и его зависимостей (раньше это была
стартовая страница; теперь стартовая — дашборд, [overview.md](overview.md)). Сама проверка `GET /api/health` открыта без входа.
Вкладка браузера подписана «SinHRM · <раздел>» на языке интерфейса.

## Как устроено
- Включение и роли модулей — таблица `module_settings` (`module`, `enabled`, `roles`), сервис `ModuleAccess`; подробности и правила — [modules-access.md](modules-access.md), решение — [ADR 0009](../adr/0009-module-access.md).
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
| `auth/auth.service.ts` | состояние сессии (signals `user`, `loading`); `GET /api/auth/me` один раз при старте, 401 → гость; `logout()`; `setActiveRole(role\|null)` → `PUT /api/auth/active-role` («Працювати як»). В состоянии `roles` — действующие роли (по ним все проверки в UI), `assigned_roles` — назначенные, `active_role` — выбор |
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
| `ui/dialog.ts` | `provideAppDialogDefaults()` в `app.config.ts` (`MAT_DIALOG_DEFAULT_OPTIONS`: `maxWidth: 95vw`, фокус на первое поле или `cdkFocusInitial`, возврат фокуса после закрытия) и `wideDialog(data, width = '720px')` — конфиг для диалога шире 560px (стандартный потолок Material M3): класс панели `app-dialog-wide` (`styles.scss`) снимает потолок, на экранах ≤600px диалог на всю ширину. Диалогам не задаём `min-width` на `mat-dialog-content` больше 560px — это даёт горизонтальную прокрутку; нужен широкий — открываем через `wideDialog()` |
| `errors/error-reporter.service.ts`, `errors/global-error-handler.ts`, `errors/server-error.interceptor.ts` | журнал ошибок браузера: `GlobalErrorHandler` (исключения JS) и `serverErrorInterceptor` (ответы 5xx) отправляют `POST /api/errors/client` — только вошедший пользователь, без повторов, не больше 20 за загрузку; [architecture/observability.md](../architecture/observability.md) |
| `http/api-error.ts` | `saveBlob(blob, name)` — скачать ответ-Blob (CSV). Маппер ошибок API переехал в `api/api-error.ts` — раздел «Общие примитивы API и UI» ниже |

| `ui/table/*` | общий заголовок таблицы с сортировкой и фильтром — раздел ниже |

Все строки интерфейса — через Transloco (`'ключ' | transloco`); новый текст добавляется во все три файла `public/i18n`.

### Заголовок таблицы: сортировка и фильтр
Один способ для всех табличных списков (2026-10-02; первым подключён `/people`, порядок остальных —
[guides/tables.md](../guides/tables.md)).

| Файл | Что |
|---|---|
| `ui/table/table-sort.directive.ts` | `<table [appTableSort]="sort()" (appTableSortChange)="…" [appTableSortClearable] [appTableSortCount]>` — состояние сортировки таблицы; по клику считает следующее: другая колонка → по возрастанию, та же → обратно; с `clearable` третий клик — порядок по умолчанию (`null`). `appTableSortCount` — сколько строк таблица показывает сейчас (клиентская — `table.rows().length`, серверная — итог API, на время загрузки `null`): открытый фильтр колонки объявляет его вежливой live-областью «Знайдено: N» |
| `ui/table/column-header.ts` | `<th scope="col" app-column-header key label [sortable] [filter] [filterValue] (filterChange)>` — название-кнопка (Enter/Space, стрелка направления, `aria-sort` на `th`, `aria-label` = название), рядом кнопка-воронка (`aria-haspopup="dialog"`, `aria-expanded`) с маленьким диалогом. **Фильтры живые, кнопки «Застосувати» нет** (2026-10-03): `text` — поле «Містить» (`aria-label` «Містить (стовпець «…»)»), список следует за вводом: «содержит», без учёта регистра, без Enter; `select` — выбор одного значения или «Усі», применяется сразу по клику (диалог остаётся открытым, стрелки ходят по списку); если вариантов больше `SELECT_SEARCH_MIN` = 8, сверху поле «Пошук варіантів» (фокус при открытии, `aria-controls` на список, «содержит», без учёта регистра; ↓ — в список, Enter — первый найденный вариант), ничего не нашлось — «Немає варіантів»; `range` — «Від/До» (`date` или `number`), применяется по change/blur после короткой паузы. Задержка вывода — `LIVE_FILTER_DEBOUNCE_MS` = 250 мс: со страничным `TableUrlState` заголовок отдаёт значение сразу внутри `url.live()`, а адрес (и запрос серверной таблицы) ждёт паузу там; без него заголовок ждёт сам. Повтор того же значения не отправляется. Enter применяет набранное сразу и закрывает, Esc закрывает и **оставляет** значение применённым, «Очистити» снимает и применённое, и ещё набираемое; фокус возвращается на воронку. Пока диалог открыт, значение из адреса поле не перезаписывает (нет цикла «адрес → поле → адрес», каретка и фокус на месте). Содержимое между тегами (например, иконка канала) показывается перед названием внутри кнопки сортировки; имя колонки для экранного чтеца — по-прежнему только название. Активный фильтр — точка на воронке и другое имя кнопки («…, увімкнено»). На сенсорных экранах кнопки ≥ 44px, фокус — кольцо `--app-focus-ring`, цвета только токенами, без анимаций при `prefers-reduced-motion` |
| `ui/table/table-state.ts` | типы (`TableSort`, `ColumnFilter`, `FilterValue`; у варианта выбора `FilterOption.i18n: true` — подпись это ключ перевода) и чистые функции: `nextSort`, `ariaSort`, `isFilterActive`, `sameQuery` (плоские запросы по значению); адрес — `sortFromParams` (неизвестная колонка отбрасывается; необязательный префикс таблицы), `sortToParams` (тоже с префиксом), `prefixed`, `intParam`, `textParam` (обрезает до `TEXT_PARAM_MAX` = 100 символов — лимит API), `oneOfParam` (только разрешённые значения), `filterToParam`, `rangeToParams`/`rangeFromParams` (`<имя>_from`/`<имя>_to`), `dateRangeFromParams`/`dateRangeToParams` (свои имена параметров, только настоящие дни `YYYY-MM-DD`, обратный диапазон меняется местами) |
| `ui/table/active-filters.ts` | `<app-active-filters [filters] (remove) (clearAll)>` — чипы включённых фильтров колонок, которых сейчас не видно (колонка скрыта на узком экране, вид «карточки»): «Колонка: значение ×», с двух — «Скинути всі»; `column` — ключ перевода; кнопки ≥ 44px на сенсорных экранах, только токены |
| `ui/table/latest-request.ts` | `LatestRequest` — «важен только последний запрос»: новый `run()` отписывается от предыдущего (HttpClient прерывает его), при уничтожении стора — тоже |
| `ui/table/table-url-state.ts` | `TableUrlState` (provide на странице): `watch(parse, apply)` — запрос из адреса при загрузке, клике и «назад/вперёд»; `update(params, {paging})` — записывает в адрес (null убирает параметр), всё кроме листания сбрасывает `page`. Живые фильтры: `live(emit, {delay, replace})` — вызовы `update()` страницы внутри `emit` копятся и сливаются, в адрес уходят одной навигацией через `delay` мс после последней правки (по умолчанию `LIVE_FILTER_DEBOUNCE_MS`); первая правка открытого фильтра добавляет запись истории, следующие её заменяют (`replaceUrl`) — «назад» снимает фильтр целиком, а не по букве. `flush()` — записать ждущую правку сейчас (Enter, Esc, закрытие); обычный `update()` забирает её с собой в ту же навигацию. Любая другая навигация («назад», переход по ссылке) и уход со страницы ждущую правку отбрасывают. Серверная таблица получает один запрос после паузы, а `LatestRequest` стора отменяет предыдущий, если он ещё в пути |
| `ui/table/client-table.ts` | Таблицы без пагинации (все строки уже пришли): `new ClientTable({ rows, columns, prefix, defaultSort })` в поле компонента с `providers: [TableUrlState]` — `rows()` отфильтрованы и отсортированы, `sort()`, `filterValue(key)`, `setSort`, `setFilter`, `clearFilters()` (снимает все фильтры таблицы одной навигацией, сортировка остаётся — когда сменился смысл строк, например группировка), `filtered()` (фильтр включён — например, итог «Разом» тогда шире видимого), `touched()`. Набранный в заголовке фильтр сразу попадает в `rows()` (локальное значение поверх адреса, пока адрес его не догнал) — строки сужаются на каждую букву без задержки, адрес пишется после паузы. Колонка — `{ key, value, filter?: 'text' \| 'select' \| 'number' \| 'date', filterValue?, sortable? }`; `columns` может быть сигналом (отчёт описывает свои колонки). Сортировка — `Intl.Collator` языка интерфейса (числа как числа, регистр не важен), пустые — в конце в обе стороны, равные — в порядке API (устойчивая сортировка). Фильтры: текст — «містить», выбор — равенство, диапазоны включительно, пустая ячейка активный фильтр не проходит. Адрес — `<префикс>_sort`, `<префикс>_dir`, `<префикс>_<колонка>` (диапазон — `_from/_to`), чтобы несколько таблиц на странице не мешали друг другу; листание не сбрасывается (его у таких таблиц нет). Помощники: `TEXT_FILTER`, `NUMBER_RANGE`, `DATE_RANGE`, `translatedSelect(values, labelKey)` (короткая запись выбора из кодов: варианты с `i18n: true`, перевод делает заголовок — второго способа переводить варианты нет), `distinctValues(rows, value)` |

Подключение и список таблиц сайта — [guides/tables.md](../guides/tables.md). Строки — `table.filter.*` (живые фильтры добавили `field`, `searchOptions`, `noOptions`, `found`; `apply` убран), `table.active.*`, `table.yes/no/actions/noMatches`. Тесты — `ui/table/table.spec.ts` (ввод → одно событие после паузы без Enter, фокус и каретка на месте; Enter; Esc оставляет значение; очистка набираемого; выбор сразу; поиск по длинному списку, ↓ и Enter; диапазон по change; live-область), `ui/table/table-extras.spec.ts`, `ui/table/client-table.spec.ts` (строки сужаются на каждую букву, адрес — одной навигацией после паузы, потом `replaceUrl`; `TableUrlState.live/flush`, отбрасывание при уходе); помощник юнит-тестов страниц — `src/testing/table-page.ts` (`openTablePage` по адресу, `clickTitle`, `column`).

### Персональные данные: общие контракты
`Contracts\PersonalDataProvider` (выгрузка и обезличивание своей части данных человека) и `Contracts\RetentionSource`
(кого можно обезличить по сроку хранения), субъект — `DTO\DataSubject` + `Enums\DataSubjectType` (`candidate`,
`employee`). Модули регистрируют их тегами; исполняет модуль Privacy — [privacy.md](privacy.md).

**Вид (рестайл C «Маршрут», 2026-10-02).** Страницы `features/core` (модули, статус, «модуль выключен»): матрица модулей в карточке `.panel`, строки разделены «треком» 1.5px, шапка — подпись `label-medium` приглушённым цветом, hover строки; заголовки — `headline-small` с трекингом темы; иконка «модуль выключен» приглушена цветом, а не прозрачностью. Тест вида — `features/core/core.restyle.spec.ts` (контракт стилей: только токены темы, без hex, линии 1.5px, без «бледности» через opacity).

### Фронтенд: общие примитивы API и UI
Код, который раньше копировался в каждую фичу (аудит фронта 02.10.2026), — один раз в `core`; фичи его только вызывают.

| Файл | Что делает |
|---|---|
| `api/api-error.ts` | `apiErrorKey(error, prefix, codes, { statuses?, fallback? })` — i18n-ключ ошибки API: известный `{code}` → `<prefix>.errors.<code>`, иначе статус из списка `statuses` (по умолчанию все: `forbidden` 403, `not_found` 404, `validation` 422, `rate_limited` 429) → `<prefix>.errors.<имя>`, иначе `fallback` (по умолчанию `common.error`). `apiErrorCode(e)` / `apiErrorStatus(e)` — код и статус ответа или `null`. Ключи каждой фичи (`performErrorKey`, `scriptsErrorKey`, …) — однострочные обёртки с её списком статусов и запасным ключом, тексты не менялись |
| `ui/notify.service.ts` | `NotifyService.show(key, { params?, duration? })` — короткое уведомление (Material snack bar) с переведённым текстом; длительность по умолчанию `NOTIFY_DURATION_MS` = 4000 мс, другие (2000/3000/5000) передаются явно там, где они были. Доступность — штатная snack bar: вежливая live-область (`aria-live="polite"`), фокус не уводится. Заменил 30+ локальных `toast()`; пока не переведены страницы с таблицами (их меняет общий заголовок таблиц — отдельная задача) и `features/people` |
| `api/api.model.ts` | общие типы ответа API: `PageMeta` (`current_page`, `per_page`, `total`, `last_page`), `Paged<T, M = PageMeta>` (`{ data: T[], meta }`; фича может расширить meta, как `VacancyPageMeta`), `DataEnvelope<T>` (`{ data: T }`). Заменили 5 своих определений страницы в фичах (`features/people` пока со своим `Paged`) |
| `api/unwrap-data.ts` | `unwrapData()` — RxJS-оператор: `http.get<DataEnvelope<T>>(url).pipe(unwrapData())` → `Observable<T>`. Заменил ~215 повторов `map((r) => r.data)` в сервисах фич |
| `api/http-params.ts` | `toParams(query)` — `HttpParams` без пустых значений (`undefined`, `null`, `''`), числа и булевы — строками (API принимает `"20"`); принимает типизированный интерфейс запроса без `{ ...query }`. Раньше жила в `recruiting.service.ts` (оттуда её импортировали 13 фич) + 3 копии; `recruiting.service.ts` пока реэкспортирует её для `features/people` |

## Как проверить
Тесты: `iso-date.spec.ts`, `app-date-adapter.spec.ts`, `datepicker-intl.spec.ts`, `channel-icon.spec.ts`, `tests/Feature/Core/HealthTest.php`, `tests/Feature/Core/OpsJobsTest.php`, `tests/Feature/Core/SecurityHeadersTest.php`, `error-reporter.spec.ts`, `tests/Unit/Core/HealthServiceTest.php`, `health.service.spec.ts`,
`api-error.spec.ts`, `http-params.spec.ts`, `unwrap-data.spec.ts`, `notify.service.spec.ts`, `auth.service.spec.ts`, `auth.guards.spec.ts`, `csrf.interceptor.spec.ts`, `language.service.spec.ts`, `translated-title.strategy.spec.ts`.
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
| `POST /api/ops/demo-fill?confirm=demo&step=<name>` | один шаг синтетики (имена с пометкой « [ТЕСТ]» в конце) для графиков отчётов (`Services/Demo/DemoDataService::STEPS`), в своей транзакции; выполненный шаг — no-op (`already`), не выполнен предыдущий → 409; `GET …&steps=list` → `{steps, done}`; `&reset=1` сначала регистрирует старые тестовые строки (`DemoLegacy`), затем удаляет только строки из `demo_records`; `GET …&reset=1&dry=1` — пробный прогон: что удалит reset (по таблицам) и побочные эффекты, ничего не меняя; без `confirm=demo` → 422. Запуск — вручную `.github/workflows/demo-fill.yml` (шаги по очереди, падает на первом не-200) |

Защита: заголовок `X-Ops-Secret` = `OPS_SECRET` (Vercel env + GitHub secret), сравнение `hash_equals`;
секрет не задан → 404 (эндпоинта «нет»), неверный → 401; после 10 неверных попыток в минуту с одного IP → 429. Считаются **только неудачные** попытки:
запрос с верным секретом не трогает кэш, поэтому миграции работают и на пустой БД (таблицы `cache` ещё нет).
В публичный лог Actions пишется только «migrations: ok/FAILED». Время выполнения ограничено `maxDuration` 60 с. Код: `Http/Middleware/RequireOpsSecret`,
`Http/Controllers/OpsMigrateController`, `Contracts/MigrationRunner` → `Services/ArtisanMigrationRunner`.
Тест: `tests/Feature/Core/OpsMigrateTest.php`, `OpsDemoFillTest.php`.

Демо-данные: ~6 мес., оргструктура сети IT-школ (`DemoDataService::blueprint()`): один филиал «Тестовий філіал» (центральный офис и 3 городских подразделения Київ/Львів/Дніпро — это отделы внутри него, например «Навчальний відділ Дніпро [ТЕСТ]»), 16 отделов, 128 сотрудников в 4–6 уровнях — CEO → 6 C-level → директора филиалов/руководители → тимлиды/старшие методисты → специалисты; у каждого руководителя ≤ 9 прямых подчинённых (типичная норма управляемости 5–8), рекрутеры — пользователи `demo+hr-1…4`; отпуска и табели утверждает непосредственный руководитель, в 360 коллеги — из той же команды; reset не удаляет тестовый филиал, на который ссылается реальная вакансия или заявка (FK без ON DELETE), 150 кандидатов (микс источников: work.ua/robota.ua впереди, карьерный сайт с `added_via=career_site`, реклама, рекомендации; воронка сужается — большинство на ранних этапах, ~12 наймов, каждый 4-й отказ с причиной из всех активных `reject_reasons`), ≥600 касаний, отпуска, табели, OKR, 1:1, 360, 2 закрытые волны Pulse (через `ResponseService`/`WaveLifecycle::close`), настроение, Desk, база знаний, активы, заявки на подбор, оценки скриптов. Пометка `[ТЕСТ]` стоит в КОНЦЕ имени (`DemoName::tag()`: «Бондаренко Андрій [ТЕСТ]», «Бухгалтер [ТЕСТ]»), чтобы списки не начинались со стены «[ТЕСТ] [ТЕСТ] …»; без пометки только сам филиал «Тестовий філіал»; e-mail `demo+<ключ>@sinhrm.test`; уникальное значение, уже занятое не-демо строкой (e-mail пользователя, инвентарный номер, имя типа актива, контакт кандидата), пропускается — реальные строки не меняются и не переиспользуются; пакетные вставки — `insertOrIgnore` (конфликт уникального ключа не обрывает транзакцию Postgres), id регистрируются по естественным ключам; оценки скриптов не пишутся для касаний, уже оценённых модулем Scripts (`EvaluateTouchpoint`), случайность — `Mt19937` с фиксированным seed (Faker только в dev). Заполнение разбито на шаги (org → people → recruiting-setup → recruiting-1…15 по 10 кандидатов → touchpoints → scripts → timeoff/time → perform → pulse-1/2 → mood/desk/knowledge/assets/hiring), чтобы каждый запрос укладывался в лимит Vercel 60 с на Neon (первый прогон одним запросом дал 504); простые данные — пакетные insert по 500 строк; шаг отмечается строкой `step:<name>` в `demo_records`. Каждая созданная строка записывается в `demo_records` (`DemoRegistry`); reset удаляет их и зависимые строки по внешнему ключу, без опоры на каскады. `APP_ENV` задаётся переменной Vercel: `production` / `preview`.

Старые тестовые данные (`Services/Demo/DemoLegacy`). До перехода на один филиал на проде остались тестовые строки, которых нет в `demo_records`: филиалы «Тестова філія А/Б», вакансия «тест», сотрудник «Анна Тестенко», имена старого вида «[ТЕСТ] …» в начале (города, филиалы, отделы, должности, сотрудники, вакансии, кандидаты, скрипты, 360, OKR, опросы, Desk, база знаний, активы, заявки, затраты каналов) и учётки на тестовом домене `@sinhrm.test`. Reset находит их только по этим строгим шаблонам, записывает в `demo_records` и удаляет вместе с остальными; всё, что не совпало, не трогается. Реальные пользователи (без `@sinhrm.test`) не удаляются и не меняются; демо-код не меняет их привязки к филиалам, но привязка к удалённому тестовому филиалу пропадает вместе с ним (это видно заранее в `effects.real_user_links_to_test_branches`). Строку, которую ещё держит внешний ключ, reset пропускает (удаление в точке сохранения, повтор до 4 проходов), а не падает. Как получить чистое состояние: Actions → «Demo data» → Run workflow с `reset` + `dry` (посмотреть, что будет удалено), затем с `reset` (удалить), затем без флагов (заполнить заново) — в итоге один тестовый филиал, у всех тестовых имён пометка в конце.

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

### «Сегодня» пользователя (`Support/UserTime`, 2026-10-02)
БД и API хранят время в UTC (`app.timezone`), интерфейс показывает местное. Граница «сегодня» (задачи на сегодня и
просрочка, «Маршрут дня», «нові сьогодні») режется по поясу пользователя, иначе с 00:00 до 02:00/03:00 по Киеву сервер
считает ещё вчерашний день. Настройки пояса у пользователя/компании нет — один пояс для всех: `config('app.user_timezone')`
(env `APP_USER_TIMEZONE`, по умолчанию `Europe/Kyiv`, летнее/зимнее время учитывает сам пояс). Значение проверяется по
`timezone_identifiers_list()`: пусто, опечатка или не-IANA (`GMT+3`) → `Europe/Kyiv`, без исключения. `UserTime::timezone()`,
`UserTime::now(?Carbon)` — момент в поясе пользователя (его `startOfDay()/endOfDay()` — день пользователя),
`UserTime::today(?Carbon)` — дата пользователя в полночь пояса хранения (замена `Carbon::today()` там, где значение —
дата: колонка `date`, `toDateString()`, сравнение с датой; используют Assets, People, TimeOff, Workflows),
`UserTime::toStorage(Carbon)` — тот же момент в поясе хранения; **обязателен** перед передачей Carbon в привязку запроса:
построитель запросов форматирует дату `Y-m-d H:i:s` без перевода, и местное время сравнилось бы с UTC.
Используют Scripts ([scripts.md](scripts.md)) и Overview ([overview.md](overview.md)). Тест: `tests/Unit/Core/UserTimeTest.php`
(23:30/00:30 UTC зимой и летом, день перехода на летнее время 29.03.2026 — 23 часа, начало `+02:00`, конец `+03:00`).

### Общие хелперы модулей (2026-10-02)
Раньше эти куски жили копиями в модулях (или в Recruiting/Reports, и другие модули тянули зависимость на них). Теперь —
в Core; поведение API то же, перенос без изменения ответов, кроме ночной границы «сегодня» (см. `UserTime::today()` выше).

| Хелпер | Что делает | Кто использует |
|---|---|---|
| `Http/Concerns/ResolvesActor` | `actor(Request): User` — пользователь запроса за `auth:sanctum` (`assert`, гость сюда не доходит) | контроллеры 20 модулей (было 31 приватная копия + трейт Recruiting `Actor`, удалён) |
| `Support/Database/Like` | `escape/contains/startsWith`: экранирует `%`, `_` и сам символ экранирования; `Like::BACKSLASH` (по умолчанию) для `like ?` без `ESCAPE` (Postgres), `Like::PORTABLE` (`!`) для `like ? escape '!'` | репозитории Assets, Directory, Knowledge, People, Recruiting (3), Reports, Scripts, Users |
| `Http/Requests/Concerns/Paginates` | `perPageRules($max = 200)` → `nullable, integer, between:1,$max`; `perPageOr($default = 50)` — `integer('perPage')`, строка `"20"` → 20 | 11 FormRequest: Audit (2, `1..100`/20), Users (`1..100`/20), Directory, People (2), Recruiting (4), TimeOff |
| `Support/ModuleServiceProvider::defineRoleGate($ability, $roles)` | gate «активный пользователь с одной из ролей»; `UserRole::hrStaff()` = набор `PeopleScope::isAdmin` | 17 gate: `*-manage` 11 HR-модулей, superadmin-only (audit, modules, integrations, users), superadmin+admin (directory, privacy) |
| `Http/Responses/Download` | `file()` — загруженный файл как attachment (ASCII-имя + `filename*`, `nosniff`, `private, no-store`, `Content-Length`); `disposition($name)` — `attachment; filename="…"` для своих имён | Desk, Documents (`file`); Privacy, People, Reports (`disposition`) |
| `Support/Export/Csv` | CSV без формул (`= + - @ \t \r` → префикс `'`), BOM, строка «Total» | Reports (`CsvResponse`), People (`bulk` export) — перенесён из `Reports/Support` |
| `Support/UserTime::today()` | «сегодня» пользователя (см. выше) | Assets, People, TimeOff, Workflows (13 вызовов `Carbon::today()`) |

Тесты: `tests/Unit/Core/{LikeTest,DownloadTest,CsvTest,ResolvesActorTest,UserTimeTest}`, `tests/Feature/Core/{PaginatesTest,RoleGateTest}`
(каждый gate × каждая роль, заблокированный пользователь, совпадение HR-gate с `PeopleScope::isAdmin`).
Не перенесены: `actor()` в Ai (там `abort(401)`, другое поведение) и Assistant (статические, модуль в ожидании решения по MCP);
~49 inline `assert($user instanceof User)` в методах — переводятся при следующих правках этих файлов.

**Границы модулей** — `tests/Unit/Core/ModuleBoundariesTest` (правило и базовый список — [architecture/overview.md](../architecture/overview.md)).

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

## Доступ к модулю

Ключ модуля `core`. Это **базовый** модуль: его нельзя выключить или ограничить по ролям на странице «Адміністрування → Модулі». Подробнее — [modules-access.md](modules-access.md).

`Contracts\UserNotifier` — короткий лист користувачу про погодження/рішення (реалізація GoogleWorkspace, див. google-workspace.md). Колонка `users.approval_emails` (default true).

`Contracts/UserNotifier` — короткий лист користувачу про погодження/рішення (реалізація GoogleWorkspace, див. google-workspace.md). Колонка `users.approval_emails` (default true).

## safeStorage.remove (2026-10-25)
`core/storage/safe-storage.ts` умеет удалять ключ (`remove`), тоже без исключений. Нужно форме вакансии: черновик
формы хранится в браузере и стирается после сохранения.
