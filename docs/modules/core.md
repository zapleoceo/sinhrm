# Модуль Core

## Что это и зачем
Общий фундамент: проверка, что система жива и видит базу данных, и базовый механизм подключения модулей.

Восстановимость схемы и синтетических связей на MySQL 8.4 проверяет отдельный CI `Backup restore proof` из HRM-38 (PR #174):
реальные миграции → связанные данные и зашифрованный vault → `mysqldump` → новая БД → чтение тем же CI APP_KEY.
Это не production backup и не проверка внешних файлов; [runbook](../guides/backup-restore.md) описывает отдельные
DB/key/storage требования и незакрытые решения владельца по RPO/RTO, retention и доступу.
Изоляцию пилота проверяет focused `ModuleAccessTest`: выключенный Workflows пропускает реальный ops tick
и сохраняет due run/step/workflow tasks; после включения шаг исполняется и последовательный tick не создаёт вторую задачу.
Это синтетический HTTP/DB test; [протокол пилота](../guides/pilot-acceptance.md) отдельно требует live login,
реальные данные API/UI, mobile/keyboard, выбранные роли и независимые AI/provider controls.

## Как пользоваться
Суперадмин и админ: меню → «Стан системи» (`/status`) показывает состояние API и его зависимостей (раньше это была
стартовая страница; теперь стартовая — дашборд, [overview.md](overview.md)). Сама проверка `GET /api/health` открыта без входа.
Вкладка браузера подписана «SinHRM · <раздел>» на языке интерфейса.
Все списки с Material-пагинацией показывают диапазон («1 – 30 із 140»), размер страницы и подсказки навигации на выбранном языке: uk, ru или en. При смене языка подписи обновляются после загрузки перевода.

## Как устроено
- Включение и роли модулей — таблица `module_settings` (`module`, `enabled`, `roles`), сервис `ModuleAccess`; подробности и правила — [modules-access.md](modules-access.md), решение — [ADR 0009](../adr/0009-module-access.md).
- `ModuleAccess::refreshSettings()` перечитывает настройки напрямую из репозитория, сохраняет снимок в текущем экземпляре и возвращает его вызывающему сервису. Следующие `allows` и `allowedKeys` проверяют этот же снимок по обычным правилам ролей и отключения модулей. Assistant использует обновление перед проверкой свежего пользователя и после ожидания брокера; общий кеш обычных запросов сохраняется. Контракт и регрессия обхода двух кешей — [modules-access.md](modules-access.md), `ModuleAccessTest`.
- `Exceptions\BusinessRuleException` — база ошибок бизнес-правил модулей (2026-10-08): код (`errorCode`, он же i18n-ключ фронта), HTTP-статус, необязательные поля `extra`; `render()` отдаёт `{message: code, code, ...extra}`. Конструктор `final protected`: модуль объявляет только именованные фабрики (`PeopleException::noEmployee()`). Наследуют 19 модульных исключений; `SafeSpeakException` остаётся отдельным — он добавляет заголовок `Retry-After`.
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
| `auth/auth.model.ts` | типы и списки ролей (`USER_ROLES` — все роли, суперадмин тоже назначается из админки с 2026-10-09, HRM-84; `INVITABLE_ROLES` убран; `HR_STAFF_ROLES`, `isHrStaff`) — зеркало `UserRole` бэкенда. С 2026-10-08 ещё `SUPERADMIN_ROLE` и `ADMIN_ROLES` (superadmin + admin) с `isAdmin(roles)` — единственное место пары «суперадмин или админ»: маршруты (`roleGuard(...ADMIN_ROLES)`, `roleGuard(SUPERADMIN_ROLE)` в `app.routes.ts`), права Privacy/Scripts/Recruiting, справка и меню оболочки, матрица модулей (`features/core/modules.page.ts`); тест — `auth.model.spec.ts` |
| `auth/auth.guards.ts` | `authGuard` (гость → `/login`), `roleGuard(...roles)` (нет ни одной из ролей → `/`; например `roleGuard('superadmin', 'admin')`), `guestGuard` (для `/login`) |
| `auth/auth.model.ts` | типы и списки ролей/статусов/языков — зеркало enum бэкенда |
| `http/csrf.interceptor.ts` | перед первым POST/PATCH/DELETE берёт `GET /sanctum/csrf-cookie`, ставит `X-XSRF-TOKEN`; на 419 — повтор один раз |
| `i18n/*` | Transloco: `public/i18n/{uk,ru,en}.json`, язык пользователя (сервер) или гостя (localStorage) |
| `i18n/translated-title.strategy.ts` | `TitleStrategy`: `title` маршрута — ключ i18n (`titles.*`), во вкладке «SinHRM · Кандидати»; при смене языка заголовок переводится заново (`selectTranslate`). Без `title` — просто «SinHRM» (он же в `index.html`) |
| `theme/theme.service.ts` | светлая/тёмная тема: по умолчанию как в ОС, выбор хранится в localStorage (`<html data-theme>`) |
| `storage/safe-storage.ts` | localStorage без исключений (приватный режим, запрет cookies) |
| `date/iso-date.ts` | даты без сдвига часового пояса: `toIsoDate(Date)` → `'YYYY-MM-DD'` по локальному календарю (не через `toISOString()`), `toIsoDateOrNull`, `fromIsoDate('YYYY-MM-DD')` → локальная полночь (переполнение вроде 31.02 → `null`), `toIsoLocalDateTime`/`fromIsoLocalDateTime` (`YYYY-MM-DDTHH:mm`, бывший формат `datetime-local`), `combineDateAndTime`, `toTimeString`/`fromTimeString` (`HH:mm`), `today()`. Контракт API не менялся: на бэкенд уходят те же строки |
| `date/iso-day.ts` | арифметика календарных дней `'YYYY-MM-DD'` через UTC-полночь (2026-10-08): `isoDayToUtc`, `utcToIsoDay`, `addIsoDays(iso, n)`, `isoWeekday` (0 — воскресенье). Переход на летнее/зимнее время день не сдвигает. Это **не** `iso-date.ts`: там API-строка ↔ локальный `Date` для datepicker, здесь `Date` — только промежуточная UTC-полночь. Используют `features/timeoff/timeoff.dates.ts` (месяц, выходные, оценка дней) и `features/time/time.model.ts` (понедельник недели, сдвиг недель, дни табеля) — раньше у каждой были свои копии. Тест — `date/iso-day.spec.ts` |
| `date/app-date-adapter.ts`, `date/provide-app-dates.ts` | `provideAppDates()` в `app.config.ts`: `AppDateAdapter` (наследник `NativeDateAdapter`, без новых зависимостей) — неделя с понедельника, в поле ввода всегда `дд.мм.рррр` (принимает также `д.м.рррр`, `дд/мм/рррр`, ISO), ISO-строки читаются как локальная дата; `APP_DATE_FORMATS` (время `HH:mm`, 24 ч). Локаль (`uk-UA`/`ru-RU`/`en-GB`) переключает `LanguageService` вместе с языком интерфейса |
| `date/datepicker-intl.ts` | подписи кнопок календаря (`common.datepicker.*`) для `MatDatepickerIntl`, обновляются при смене языка; грузится динамическим `import()` — статический импорт тянул весь datepicker в стартовый бандл (+~340 kB) |
| `ui/logo.ts` | `<app-logo [variant]="'mark'\|'full'" [mono]>` — логотип SinHRM: квадрат с вырезанной «S» (цвет `--app-brand`) + словесный знак Onest; `role=img`, `aria-label="SinHRM"`. Используется на странице входа и в шапке сайдбара; из него же `public/favicon.svg` и растровые иконки (`scripts/gen-icons.mjs`), см. [design-direction.md](../architecture/design-direction.md) §8 |
| `ui/channel-icon.ts`, `ui/channel-icons.ts` | `<app-channel-icon [key] [label]>` — иконка Font Awesome Free канала / источника / интеграции в цвете бренда: telegram, whatsapp, viber, linkedin, meta_ads, google/gmail/sheets/calendar — брендовые; work_ua/robota_ua/djinni/dou — портфель с буквой (в FA Free их нет); телефония — `faPhoneVolume`, AI — робот/мозг, Deepgram — волна; неизвестный ключ — нейтральный знак вопроса. С `label` — `role=img` + `aria-label` + подсказка, без — декоративная (`aria-hidden`), когда название написано рядом. Используется в карточке кандидата (контакты, чипы источника, фильтры и лента), композере, «Вхідних», дашборде, отчётах, каналах залучення, интеграциях, Google-подключении, странице расширения |
| `ui/event-value.ts` | `eventValue(event)` (2026-10-08) — текст поля (input/textarea/select), вызвавшего событие; страницы подключают его полем `protected readonly val = eventValue`, шаблоны (`val($event)`) не менялись. Заменил 15 одинаковых методов `val()/value()` (desk, documents, hiring-requests, knowledge, reports, safe-speak, scripts, справка, workflows). Тест — `ui/event-value.spec.ts` |
| `ui/with-member.ts` | `withMember(set, value, on)` (2026-10-08) — новый `Set` с добавленным/убранным значением для `signal.update()`: «строки с запросом в пути» (`pending`) сторов directory, users, board, runs, tasks, integrations, раскрытые узлы оргструктуры, очищенные поля секретов интеграции. Заменил 8 одинаковых `setPending`/`mark`/`release`. Тест — `ui/with-member.spec.ts` |
| `ui/dialog.ts` | `provideAppDialogDefaults()` в `app.config.ts` (`MAT_DIALOG_DEFAULT_OPTIONS`: `maxWidth: 95vw`, фокус на первое поле или `cdkFocusInitial`, возврат фокуса после закрытия) и `wideDialog(data, width = '720px')` — конфиг для диалога шире 560px (стандартный потолок Material M3): класс панели `app-dialog-wide` (`styles.scss`) снимает потолок, на экранах ≤600px диалог на всю ширину. Диалогам не задаём `min-width` на `mat-dialog-content` больше 560px — это даёт горизонтальную прокрутку; нужен широкий — открываем через `wideDialog()` |
| `errors/error-reporter.service.ts`, `errors/global-error-handler.ts`, `errors/server-error.interceptor.ts` | журнал ошибок браузера: `GlobalErrorHandler` (исключения JS) и `serverErrorInterceptor` (ответы 5xx) отправляют `POST /api/errors/client` — только вошедший пользователь, без повторов, не больше 20 за загрузку; [architecture/observability.md](../architecture/observability.md) |
| `http/api-error.ts` | `saveBlob(blob, name)` — скачать ответ-Blob (CSV). Маппер ошибок API переехал в `api/api-error.ts` — раздел «Общие примитивы API и UI» ниже |

| `ui/table/*` | общий заголовок таблицы с сортировкой и фильтром — раздел ниже |
| `ui/styles/_sortable-items.scss`, `ui/styles/_service-panel.scss` | Sass-миксины общих стилей (2026-10-08): `sortable-items.editor` — тулбар, список карточек с ручкой и номером, превью и место перетаскивания CDK (редакторы скриптов и воркфлоу); `service-panel.base` — карточка подключения сервиса на странице интеграций (`ai-panel`, `google-connect.panel`); `_trace.scss` — анимация «trace» полосы прогресса: `trace.fill(селектор, цвет)` (заполнение дорожки) и `trace.draw(селектор)` (только прорисовка), 600 мс, без анимации при reduced motion — Perform (OKR, продуктивность, циклы, результаты 360), заявки на подбор, графики отчётов; у входа своя (задержка и кривая другие). Подключение: `@use '…/core/ui/styles/sortable-items'; @include sortable-items.editor;` (работает и во встроенных `styles` — `inlineStyleLanguage: scss`). Глобальные утилиты `.small`, `.spacer`, `.rows` — в `src/styles.scss`, описание — [design-direction.md](../architecture/design-direction.md) |

Мёртвые экспорты (knip, 2026-10-08): константы и функции, которыми пользуется только свой файл, больше не экспортируются (`API_ERROR_STATUSES`, `CsrfTokenService`, `APP_TITLE`, `THEME_STORAGE_KEY`, `compareCells`, `SELECT_SEARCH_MIN` и ~35 в фичах: `*_COLUMNS`, `*QueryFromParams` страниц, помощники `org-layout.ts`); неиспользуемые вовсе удалены (`monthKey`, `OBJECTIVE_SCOPES/STATUSES`, типы `AiErrorCode`, `LoginErrorCode`); `features/people` и `features/recruiting` берут `Paged` и `toParams` прямо из `core/api` (реэкспорт `toParams` из `recruiting.service.ts` и свой `Paged` People удалены). Экспортированными оставлены типы параметров публичных помощников core (`ApiErrorKeyOptions`, `NotifyOptions`, `PagedListOptions`, `ClientTableOptions`, `LiveEditOptions`, …) и типы ответов API в `*.model.ts` — это контракт, которым пользуется вызывающий код.

Все строки интерфейса — через Transloco (`'ключ' | transloco`); новый текст добавляется во все три файла `public/i18n`.

### Заголовок таблицы: сортировка и фильтр
Один способ для всех табличных списков (2026-10-02; первым подключён `/people`, порядок остальных —
[guides/tables.md](../guides/tables.md)).

| Файл | Что |
|---|---|
| `ui/table/table-sort.directive.ts` | `<table [appTableSort]="sort()" (appTableSortChange)="…" [appTableSortClearable] [appTableSortCount]>` — состояние сортировки таблицы; по клику считает следующее: другая колонка → по возрастанию, та же → обратно; с `clearable` третий клик — порядок по умолчанию (`null`). `appTableSortCount` — сколько строк таблица показывает сейчас (клиентская — `table.rows().length`, серверная — итог API, на время загрузки `null`): открытый фильтр колонки объявляет его вежливой live-областью «Знайдено: N». С 2026-10-03 подключён у **всех** таблиц с заголовками (без него диалог молчит) |
| `ui/table/column-header.ts` | `<th scope="col" app-column-header key label [sortable] [filter] [filterValue] (filterChange)>` — название-кнопка (Enter/Space, стрелка направления, `aria-sort` на `th`, `aria-label` = название), рядом кнопка-воронка (`aria-haspopup="dialog"`, `aria-expanded`) с маленьким диалогом. **Фильтры живые, кнопки «Застосувати» нет** (2026-10-03): `text` — поле «Містить» (`aria-label` «Містить (стовпець «…»)»), список следует за вводом: «содержит», без учёта регистра, без Enter; `select` — выбор одного значения или «Усі», применяется сразу по клику, пробелу или Enter (диалог остаётся открытым); шаги стрелками (↑↓←→, Home/End — в радиогруппе каждый шаг выбирает) ждут ту же паузу, что и ввод: пробежка по 30 вариантам — один запрос, а не 30, Enter отправляет выбранное сразу; если вариантов больше `SELECT_SEARCH_MIN` = 8, сверху поле «Пошук варіантів» (фокус при открытии, `aria-controls` на список, «содержит», без учёта регистра; ↓ — в список, Enter — первый найденный вариант), ничего не нашлось — «Немає варіантів»; `range` — «Від/До» (`date` или `number`), применяется по change/blur после короткой паузы. Задержка вывода — `LIVE_FILTER_DEBOUNCE_MS` = 250 мс: со страничным `TableUrlState` заголовок отдаёт значение сразу внутри `url.live()`, а адрес (и запрос серверной таблицы) ждёт паузу там; без него заголовок ждёт сам. Повтор того же значения не отправляется. Enter применяет набранное сразу и закрывает, Esc закрывает и **оставляет** значение применённым, «Очистити» снимает и применённое, и ещё набираемое; фокус возвращается на воронку. Пока диалог открыт, своё же значение, вернувшееся из адреса, поле не перезаписывает (нет цикла «адрес → поле → адрес», каретка и фокус на месте); а если адрес сменился извне («назад», пока диалог открыт; своих правок в пути нет — `TableUrlState.writing()` пуст), поле и «последнее отправленное» берут значение таблицы, и следующая правка снова добавляет запись истории. Enter и Esc во время IME-композиции (`isComposing`, `keyCode` 229) диалог не закрывают — это Enter выбора слова. «Знайдено: N» обновляется через `COUNT_ANNOUNCE_DELAY_MS` = 500 мс после того, как число перестало меняться (при открытии — сразу): чтец не проговаривает каждую букву. Содержимое между тегами (например, иконка канала) показывается перед названием внутри кнопки сортировки; имя колонки для экранного чтеца — по-прежнему только название. Активный фильтр — точка на воронке и другое имя кнопки («…, увімкнено»). На сенсорных экранах кнопки ≥ 44px, фокус — кольцо `--app-focus-ring`, цвета только токенами, без анимаций при `prefers-reduced-motion` |
| `ui/table/table-state.ts` | типы (`TableSort`, `ColumnFilter`, `FilterValue`; у варианта выбора `FilterOption.i18n: true` — подпись это ключ перевода) и чистые функции: `nextSort`, `ariaSort`, `isFilterActive`, `sameQuery` (плоские запросы по значению); адрес — `sortFromParams` (неизвестная колонка отбрасывается; необязательный префикс таблицы), `sortToParams` (тоже с префиксом), `prefixed`, `intParam`, `textParam` (обрезает до `TEXT_PARAM_MAX` = 100 символов — лимит API), `oneOfParam` (только разрешённые значения), `filterToParam`, `rangeToParams`/`rangeFromParams` (`<имя>_from`/`<имя>_to`), `dateRangeFromParams`/`dateRangeToParams` (свои имена параметров, только настоящие дни `YYYY-MM-DD`, обратный диапазон меняется местами) |
| `ui/table/active-filters.ts` | `<app-active-filters [filters] (remove) (clearAll)>` — чипы включённых фильтров колонок, которых сейчас не видно (колонка скрыта на узком экране, вид «карточки»): «Колонка: значение ×», с двух — «Скинути всі»; `column` — ключ перевода; кнопки ≥ 44px на сенсорных экранах, только токены |
| `ui/table/latest-request.ts` | `LatestRequest` — «важен только последний запрос»: новый `run()` отписывается от предыдущего (HttpClient прерывает его), при уничтожении стора — тоже |
| `ui/table/paged-list.ts` | `PagedList<T>` (2026-10-08) — состояние одного списка поверх `LatestRequest`: сигналы `items`, `total`, `loading`, `failed` и `load(request$, { map?, next?, error? })`. Ответ — страница Laravel (`{ data, meta.total }`) или массив (тогда `total` = длина); `map` превращает строку API в строку таблицы, `next` берёт из ответа ещё что-то (счётчик `meta.active_count`), `error` — уведомление; при ошибке показанные строки остаются. Новый `load()` отменяет запрос в пути. Заменил ручные счётчики `seq` в 8 сторах и одинаковые `load()` 25 страниц/сторов (people, directory, audit, users, assets, desk, hiring-requests, inbox; perform — 4 страницы, time — 2, documents — 3, scripts, workflows, safe-speak, knowledge, pulse, timeoff); сторы без списка (профиль, доска, календарь, карточка кандидата) используют `LatestRequest` напрямую. Тест — `ui/table/paged-list.spec.ts` |
| `ui/table/table-url-state.ts` | `TableUrlState` (provide на странице): `watch(parse, apply)` — запрос из адреса при загрузке, клике и «назад/вперёд»; `update(params, {paging})` — записывает в адрес (null убирает параметр), всё кроме листания сбрасывает `page`. Живые фильтры: `live(emit, {delay, replace})` — вызовы `update()` страницы внутри `emit` копятся и сливаются, в адрес уходят одной навигацией через `delay` мс после последней правки (по умолчанию `LIVE_FILTER_DEBOUNCE_MS`); первая правка открытого фильтра добавляет запись истории, следующие её заменяют (`replaceUrl`) — «назад» снимает фильтр целиком, а не по букве. `flush()` — записать ждущую правку сейчас (Enter, Esc, закрытие); обычный `update()` забирает её с собой в ту же навигацию. Любая другая навигация («назад», переход по ссылке) и уход со страницы ждущую правку отбрасывают. **Записи не перекрываются** (2026-10-03): `flush()`, пока предыдущая навигация ещё идёт, не запускает вторую — правка ждёт её завершения и уходит следом (вторая навигация с `replaceUrl` поверх незавершённой первой заменила бы запись до фильтра, и «назад» уводил бы со страницы); если первая не удалась (guard), ждущая правка забирает её параметры и режим «новая запись». `writing()` — сигнал параметров на пути в адрес (в очереди или в навигации), `null` — ничего не ждёт. Помощники серверных таблиц (2026-10-08, были копиями в audit/users/directory/people): `setPage(pageEvent)` → `page`/`perPage` без сброса страницы, `setSort(sort)` → `sort`/`dir`, `setFilter(name, value)` → один параметр (пусто — убрать); последние два возвращают на страницу 1. В `table-state.ts` — `idToFilter(id)` (значение выбора из id запроса, нет id — «Усі»). Серверная таблица получает один запрос после паузы, а `LatestRequest` стора отменяет предыдущий, если он ещё в пути |
| `ui/table/client-table.ts` | Таблицы без пагинации (все строки уже пришли): `new ClientTable({ rows, columns, prefix, defaultSort })` в поле компонента с `providers: [TableUrlState]` — `rows()` отфильтрованы и отсортированы, `sort()`, `filterValue(key)`, `setSort`, `setFilter`, `clearFilters()` (снимает все фильтры таблицы одной навигацией, сортировка остаётся — когда сменился смысл строк, например группировка), `filtered()` (фильтр включён — например, итог «Разом» тогда шире видимого), `touched()`. Набранный в заголовке фильтр сразу попадает в `rows()` — строки сужаются на каждую букву без задержки, адрес пишется после паузы. Адрес разбирается отдельным `computed` только при его смене; набранное накладывается сверху, пока его параметры в пути (`TableUrlState.writing()` содержит ровно их: в очереди или в навигации) — между сбросом очереди и приходом нового адреса строки не мигают старым значением. Правку отбросила другая навигация («назад», переход, навигация без смены query) — показывается только адрес, фильтра, которого в адресе нет, таблица не держит. Колонка — `{ key, value, filter?: 'text' \| 'select' \| 'number' \| 'date', filterValue?, sortable? }`; `columns` может быть сигналом (отчёт описывает свои колонки). Сортировка — `Intl.Collator` языка интерфейса (числа как числа, регистр не важен), пустые — в конце в обе стороны, равные — в порядке API (устойчивая сортировка). Фильтры: текст — «містить», выбор — равенство, диапазоны включительно, пустая ячейка активный фильтр не проходит. Адрес — `<префикс>_sort`, `<префикс>_dir`, `<префикс>_<колонка>` (диапазон — `_from/_to`), чтобы несколько таблиц на странице не мешали друг другу; листание не сбрасывается (его у таких таблиц нет). Помощники: `TEXT_FILTER`, `NUMBER_RANGE`, `DATE_RANGE`, `translatedSelect(values, labelKey)` (короткая запись выбора из кодов: варианты с `i18n: true`, перевод делает заголовок — второго способа переводить варианты нет), `distinctValues(rows, value)` |

Подключение и список таблиц сайта — [guides/tables.md](../guides/tables.md). Строки — `table.filter.*` (живые фильтры добавили `field`, `searchOptions`, `noOptions`, `found`; `apply` убран), `table.active.*`, `table.yes/no/actions/noMatches`. Тесты — `ui/table/table.spec.ts` (ввод → одно событие после паузы без Enter, фокус и каретка на месте; Enter; Esc оставляет значение; очистка набираемого; выбор сразу; поиск по длинному списку, ↓ и Enter; диапазон по change; live-область), `ui/table/table-extras.spec.ts`, `ui/table/client-table.spec.ts` (строки сужаются на каждую букву, адрес — одной навигацией после паузы, потом `replaceUrl`; нет мигания между двумя записями; «назад» сбрасывает очередь по NavigationStart и подменяет набранное значением адреса в строках и в поле; `TableUrlState.live/flush`, вторая запись ждёт первую, неудачная первая отдаёт параметры, отбрасывание при уходе). Таймеры в этих тестах — фальшивые (`vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })`, пауза ровно `LIVE_FILTER_DEBOUNCE_MS`), без реального ожидания с запасом; в `table.spec.ts` ещё стрелки по списку (один вывод), IME-Enter, подмена черновика при внешней смене и отложенное «Знайдено: N»; помощник юнит-тестов страниц — `src/testing/table-page.ts` (`openTablePage` по адресу, `clickTitle`, `column`, `sortCount(fixture, n)` — число, которое n-я таблица страницы отдаёт фильтрам через `appTableSortCount`).

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
| `ui/paginator-intl.ts` | Общий `MatPaginatorIntl`, подключённый в `app.config.ts`: пять Material-подписей и связка диапазона из `common.paginator.*`; `selectTranslateObject` следует `LanguageService` через активный язык Transloco и ждёт загрузки словаря, `changes` обновляет существующие пагинаторы, `takeUntilDestroyed` завершает подписку. Локализация заменяет только связку «of» штатного `MatPaginatorIntl`: числовой диапазон, пробелы и поведение пустых, отрицательных и выходящих за пределы страниц сохраняются. Провайдер не меняет индекс страницы, URL или API-запрос и не подменяет диапазон запрошенной страницы диапазоном другой |
| `ui/notify.service.ts` | `NotifyService.show(key, { params?, duration? })` — короткое уведомление (Material snack bar) с переведённым текстом; длительность по умолчанию `NOTIFY_DURATION_MS` = 4000 мс, другие (2000/3000/5000) передаются явно там, где они были. Доступность — штатная snack bar: вежливая live-область (`aria-live="polite"`), фокус не уводится. Заменил 30+ локальных `toast()`; 2026-10-08 переведены и оставшиеся 21 вызов `MatSnackBar.open(i18n.translate(…))` (страницы с таблицами, `features/people`, Perform, Pulse, Scripts, Time, Mail, Google Sheets) — длительности 3000/5000 переданы явно. Свой `MatSnackBar` остался только у `people/hire.action.ts`: уведомление с кнопкой «Відкрити», `NotifyService` кнопок не умеет |
| `api/api.model.ts` | общие типы ответа API: `PageMeta` (`current_page`, `per_page`, `total`, `last_page`), `Paged<T, M = PageMeta>` (`{ data: T[], meta }`; фича может расширить meta, как `VacancyPageMeta`), `DataEnvelope<T>` (`{ data: T }`). Заменили 5 своих определений страницы в фичах (в `features/recruiting` и `features/timeoff` остался только реэкспорт типа `Paged`) |
| `api/unwrap-data.ts` | `unwrapData()` — RxJS-оператор: `http.get<DataEnvelope<T>>(url).pipe(unwrapData())` → `Observable<T>`. Заменил ~215 повторов `map((r) => r.data)` в сервисах фич |
| `api/http-params.ts` | `toParams(query)` — `HttpParams` без пустых значений (`undefined`, `null`, `''`), числа и булевы — строками (API принимает `"20"`); принимает типизированный интерфейс запроса без `{ ...query }`. Раньше жила в `recruiting.service.ts` (оттуда её импортировали 13 фич) + 3 копии; теперь все фичи, включая `features/people` и `features/recruiting`, импортируют её отсюда напрямую |

**Граница дня пользователя и страж (2026-10-08).** `Core\Support\UserTime` дополнен `wallTime($date, $hour, $minute)` («в этот день в 18:00 по Киеву» как момент UTC) и `endOfDay($date)` (последняя секунда дня по Киеву в UTC). Unit-тест `tests/Unit/Core/UserDayGuardTest.php` (в стиле `ModuleBoundariesTest`) сканирует `backend/app` и падает на новой календарной операции от часов UTC: `Carbon::today()/yesterday()/tomorrow()`, `today()`, `Carbon::now()`/`now()` и `$now` с `startOfDay/Week/Month/Year`, `endOf…`, `toDateString`, `year`, `month`, `dayOfWeek`, `format('Y…')`. Моменты (created_at, сроки хранения, SLA в часах) не ловятся. Осознанный UTC — строка в `ALLOWED` с причиной (сейчас: дневные лимиты AI и статистика AI по UTC; `$now`, уже переведённый в пояс пользователя, в `DayRouteService` и `EloquentTaskRepository`); исправленная строка удаляется из списка. Именованные лимитеры модулей — `ModuleServiceProvider::definePerUserLimiter($name, $perMinute)`: своя корзина `<name>|<id пользователя или IP>` (Desk, Documents, Reports, TimeOff).

## Как проверить
Тесты: `iso-date.spec.ts`, `iso-day.spec.ts`, `app-date-adapter.spec.ts`, `datepicker-intl.spec.ts`, `channel-icon.spec.ts`, `tests/Feature/Core/HealthTest.php`, `tests/Feature/Core/OpsJobsTest.php`, `tests/Feature/Core/SecurityHeadersTest.php`, `error-reporter.spec.ts`, `tests/Unit/Core/HealthServiceTest.php`, `health.service.spec.ts`,
`paginator-intl.spec.ts` (все подписи ru/uk/en, совпадение числовых диапазонов со штатным `MatPaginatorIntl`, отложенная загрузка/быстрая смена языка, aria-label существующего пагинатора, освобождение подписки), `api-error.spec.ts`, `http-params.spec.ts`, `unwrap-data.spec.ts`, `notify.service.spec.ts`, `auth.service.spec.ts`, `auth.guards.spec.ts`, `csrf.interceptor.spec.ts`, `language.service.spec.ts`, `translated-title.strategy.spec.ts`.
Вручную: `curl -i https://sinhrm.vercel.app/api/health`.

## Заголовки безопасности
`Http\Middleware\SecurityHeaders` (подключён в `bootstrap/app.php`) ставит на каждый ответ API CSP `default-src 'none'`,
`X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS; `/api/docs` — без CSP. Для SPA те же
заголовки задаёт `frontend/vercel.json`. Подробно и почему так — [architecture/observability.md](../architecture/observability.md).

## Точка входа Vercel
`backend/api/index.php` подменяет `SCRIPT_NAME` на `/index.php`: иначе Laravel считает `/api` базовым путём и
`/api/health` превращается в `/health` (404). API-only: страниц нет; единственные маршруты группы `web` — `/api/auth/google/*` (модуль Auth) и `/api/google/connect*` (OAuth-подключение GoogleWorkspace); на `sinhrm-api.vercel.app/` — 404. Публичный `sinhrm.vercel.app/` — это фронтенд.
Контракт проверки здоровья — `/api/health` (зависимости); `/up` — встроенная проверка Laravel «процесс жив», без БД.

## IP клиента за Vercel (доверенные прокси, 2026-10-03)
**Проблема.** Рантайм `vercel-php@0.9.0` запускает встроенный сервер PHP и сам шлёт ему запрос из Node-лаунчера по
`localhost:3000` (`src/launchers/builtin.ts`), поэтому `REMOTE_ADDR` всегда `127.0.0.1`, а заголовки запроса от
края Vercel передаются как есть. Без доверия к прокси `$request->ip()` = `127.0.0.1` у всех: лимиты «по IP» общие на
всех (Safe Speak 5 в час, карьерная страница 5 в час, лимитеры Assistant/Channels/Observability, `ops:ip` в
`RequireOpsSecret`) — один человек мог исчерпать лимит за всех.

**Решение.** Встроенный глобальный middleware Laravel `TrustProxies` читает `config('trustedproxy.proxies')`
(`config/trustedproxy.php`, env `TRUSTED_PROXIES`); в `bootstrap/app.php` доверяем **только** заголовку
`X-Forwarded-For` (`trustProxies(headers: HEADER_X_FORWARDED_FOR)`) — Host/Proto/Port/Prefix из заголовков не берутся.
В `backend/vercel.json` `TRUSTED_PROXIES=127.0.0.1,::1` (только loopback-лаунчер). Все места берут IP через
`$request->ip()`, поэтому отдельного хелпера нет — источник один, middleware.

**Почему клиент не подделает IP.** (1) Доверяем только адресу loopback: прямой собеседник с другим `REMOTE_ADDR`
(например, локальный запуск без Vercel) заголовок не меняет. (2) Документация Vercel
([Request headers](https://vercel.com/docs/headers/request-headers), `x-forwarded-for`): край Vercel **перезаписывает**
`X-Forwarded-For` публичным IP клиента и не пересылает внешние значения «to prevent IP spoofing». (3) Если цепочка всё же
придёт, Symfony берёт самый правый недоверенный адрес — дописанный прокси, а не первый от клиента.
**Дефолт безопасный:** `TRUSTED_PROXIES` не задан → не доверяем никому, `ip()` = `REMOTE_ADDR`. Значение `*` не
использовать: оно доверяет заголовку от любого собеседника.

**Анонимность Safe Speak не меняется:** IP по-прежнему не хранится и не логируется, дальше контроллера идёт только
`ClientBucket` (HMAC с `APP_KEY`); меняется лишь то, что корзины разных людей теперь разные.

**Не проверено на проде (оговорка).** SPA ходит в API через внешний rewrite `sinhrm.vercel.app/api/*` →
`sinhrm-api.vercel.app` (`frontend/vercel.json`). Что при таком rewrite Vercel кладёт в `X-Forwarded-For` у API —
IP клиента или адрес края фронтенд-проекта — документация прямо не говорит. Во втором случае лимиты для запросов через
фронтенд останутся общими (как сейчас, без регрессии), а прямые запросы к `sinhrm-api.vercel.app` уже разделятся.
Проверка после деплоя (ничего не создаёт): из сети A 11 раз
`POST https://sinhrm.vercel.app/api/safe-speak/public/follow-up` с заведомо неверным кодом — 10 × 404 `invalid_code`,
11-й 429; сразу же из другой сети B (мобильный интернет) один такой же запрос — ожидается 404, а не 429. 429 в сети B
значит, что через rewrite IP клиента не доходит; повторить то же напрямую на `sinhrm-api.vercel.app` (корзина A на
15 минут). Тест: `tests/Feature/Core/TrustedProxiesTest.php`.

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

Демо-данные: ~6 мес., оргструктура сети IT-школ (`DemoDataService::blueprint()`): один филиал «Тестовий філіал» (центральный офис и 3 городских подразделения Київ/Львів/Дніпро — это отделы внутри него, например «Навчальний відділ Дніпро [ТЕСТ]»), 16 отделов, 128 сотрудников в 4–6 уровнях — CEO → 6 C-level → директора филиалов/руководители → тимлиды/старшие методисты → специалисты; у каждого руководителя ≤ 9 прямых подчинённых (типичная норма управляемости 5–8), рекрутеры — пользователи `demo+hr-1…4`; отпуска и табели утверждает непосредственный руководитель, в 360 коллеги — из той же команды; reset не удаляет тестовый филиал, на который ссылается реальная вакансия или заявка (FK без ON DELETE), 150 кандидатов (микс источников: work.ua/robota.ua впереди, карьерный сайт с `added_via=career_site`, реклама, рекомендации; воронка сужается — большинство на ранних этапах, ~12 наймов, каждый 4-й отказ с причиной из всех активных `reject_reasons`), ≥600 касаний, отпуска, табели, OKR, 1:1, 360, 2 закрытые волны Pulse (через `ResponseService`/`WaveLifecycle::close`), настроение, Desk, база знаний, активы, заявки на подбор, оценки скриптов. Пометка `[ТЕСТ]` стоит в КОНЦЕ имени (`DemoName::tag()`: «Бондаренко Андрій [ТЕСТ]», «Бухгалтер [ТЕСТ]»), чтобы списки не начинались со стены «[ТЕСТ] [ТЕСТ] …»; без пометки только сам филиал «Тестовий філіал»; e-mail `demo+<ключ>@sinhrm.test`; уникальное значение, уже занятое не-демо строкой (e-mail пользователя, инвентарный номер, имя типа актива, контакт кандидата), пропускается — реальные строки не меняются и не переиспользуются; пакетные вставки — `insertOrIgnore` (строка с занятым уникальным ключом пропускается, шаг продолжается), id регистрируются по естественным ключам; оценки скриптов не пишутся для касаний, уже оценённых модулем Scripts (`EvaluateTouchpoint`), случайность — `Mt19937` с фиксированным seed (Faker только в dev). Заполнение разбито на шаги (org → people → recruiting-setup → recruiting-1…15 по 10 кандидатов → touchpoints → scripts → timeoff/time → perform → pulse-1/2 → mood/desk/knowledge/assets/hiring), чтобы каждый запрос укладывался в лимит хостинга 60 с (первый прогон одним запросом дал 504); простые данные — пакетные insert по 500 строк; шаг отмечается строкой `step:<name>` в `demo_records`. Каждая созданная строка записывается в `demo_records` (`DemoRegistry`); reset удаляет их и зависимые строки по внешнему ключу, без опоры на каскады. `APP_ENV` задаётся переменной Vercel: `production` / `preview`.

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
| `Support/Database/Like` | `escape/contains/startsWith`: экранирует `%`, `_` и сам символ экранирования; `Like::BACKSLASH` (по умолчанию) для `like ?` без `ESCAPE` (MySQL по умолчанию экранирует обратной косой), `Like::PORTABLE` (`!`) для `like ? escape '!'` | репозитории Assets, Directory, Knowledge, People, Recruiting (3), Reports, Scripts, Users |
| `Http/Requests/Concerns/Paginates` | `perPageRules($max = 200)` → `nullable, integer, between:1,$max`; `perPageOr($default = 50)` — `integer('perPage')`, строка `"20"` → 20 | 11 FormRequest: Audit (2, `1..100`/20), Users (`1..100`/20), Directory, People (2), Recruiting (4), TimeOff |
| `Http/Requests/Concerns/HasSubjectAndBody` | `subjectAndBodyRules()` → `subject: required, string, max:200`, `body: required, string, max:10000`; `subject()` (trim), `body()` (как ввели) | Desk `OpenCaseRequest`, SafeSpeak `SubmitReportRequest` (2026-10-08, было два одинаковых набора) |
| `Support/ModuleServiceProvider::defineRoleGate($ability, $roles)` | gate «активный пользователь с одной из ролей»; `UserRole::hrStaff()` = набор `PeopleScope::isAdmin` | 17 gate: `*-manage` 11 HR-модулей, superadmin-only (audit, modules, integrations, users), superadmin+admin (directory, privacy) |
| `Http/Responses/Download` | `file()` — загруженный файл как attachment (ASCII-имя + `filename*`, `nosniff`, `private, no-store`, `Content-Length`); `disposition($name)` — `attachment; filename="…"` для своих имён | Desk, Documents (`file`); Privacy, People, Reports (`disposition`) |
| `Support/Export/Csv` | CSV без формул (`= + - @ \t \r` → префикс `'`), BOM, строка «Total» | Reports (`CsvResponse`), People (`bulk` export) — перенесён из `Reports/Support` |
| `Support/UserTime::today()` | «сегодня» пользователя (см. выше) | Assets, People, TimeOff, Workflows (13 вызовов `Carbon::today()`) |

Тесты: `tests/Unit/Core/{LikeTest,DownloadTest,CsvTest,ResolvesActorTest,UserTimeTest,HasSubjectAndBodyTest,BusinessRuleExceptionTest}`, `tests/Feature/Core/{PaginatesTest,RoleGateTest}`
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

## safeStorage.remove (2026-10-25)
`core/storage/safe-storage.ts` умеет удалять ключ (`remove`), тоже без исключений. Нужно форме вакансии: черновик
формы хранится в браузере и стирается после сохранения.

## SHA сборки Web и API
Deploy генерирует `backend/build.json` и `frontend/public/build.json` из `git rev-parse HEAD`
после checkout `workflow_run.head_sha`, до сборки. SHA событий `github.sha` и переменные Vercel не используются:
в workflow_run они могут относиться к main, а preview собирается из другой ревизии.
API `GET /api/health` возвращает полный SHA в существующем поле `version`; `ok`, `checks` и HTTP 200/503 сохранены.
Если метаданных нет либо SHA некорректен, возвращается `dev`; APP_VERSION не подтверждает происхождение сборки.
Web `GET /build.json` отдаёт только `{sha}` как статический asset с `Cache-Control: no-store`.
SHA Web и API сверяют отдельно: production gate может обновить только один проект, поэтому они законно различаются.
Generated файлы игнорируются git; новых обязательных env нет. Ручной обход Deploy без stamping не доказывает SHA.
Проверка: `node --test scripts/stamp-build.test.mjs`, backend `HealthTest`, `BuildVersionTest`.
После разрешённого deploy сравнить `/build.json` и `/api/health` с HEAD конкретного успешного Deploy checkout.
До этого runtime provenance не считается подтверждённым.

## SQL на MySQL 8.4 (2026-10-08; только MySQL — с ADR 0011)

Единственная СУБД — MySQL 8.4 ([ADR 0011](../adr/0011-mysql-only.md), заменил двойную поддержку [ADR 0010](../adr/0010-mysql-dual-support.md)). `DB_CONNECTION` по умолчанию — `mysql`; CI-страж `scripts/mysql-only-guard.mjs` (job `lint`) падает, если упоминание другой СУБД появляется вне разового инструмента переезда.

- `Core\Support\Database\Sql` — то, чего нет в билдере Laravel на MySQL: `orderByNullsLast/First` (в MySQL нет `NULLS LAST/FIRST`: `expr is null asc/desc, expr dir`), `whereContainsCi` (`lower(..) like ? escape '!'`, подстановочные знаки литеральны), `jsonText` (`json_unquote(json_extract(..))` — то же, что Laravel строит для `'col->key'`), `castText` (`cast(.. as char(n))`, MySQL не знает `CAST AS VARCHAR/TEXT`). API прежний; `jsonText`/`castText` с драйвером, отличным от `mysql`/`mariadb`, — `InvalidArgumentException`. Выражение — только идентификатор колонки (`col`/`table.col`); подзапрос или вычисляемое — явным `new Illuminate\Database\Query\Expression(...)`, иная строка (пробелы, кавычки, `;`, `--`) → `InvalidArgumentException`. `jsonText`: JSON null даёт строку `'null'`, отсутствующий ключ — SQL NULL. Апсерты — `upsert()/insertOrIgnore()/insertGetId()` Laravel, JSON в `where` — `'col->key'`/`whereJsonContains`.
- Соединение `mysql` (`config/database.php`): `utf8mb4`, collation `utf8mb4_0900_ai_ci` (`DB_COLLATION`), `strict` (включая `ONLY_FULL_GROUP_BY`), сессия `+00:00`, InnoDB. Проверка — `PortableSqlTest` (job `tests` на MySQL 8.4: NULLS LAST/FIRST, «содержит» с кириллицей и `é = e`, JSON, сессия 8.4/UTC/strict), `SqlTest`.

## Разовый инструмент переезда `db:transfer-to-mysql` (2026-10-08, PROD-47; удалить после cutover)

Команда переноса боевых данных на MySQL 8.4 — единственное исключение из «только MySQL» ([ADR 0011](../adr/0011-mysql-only.md)). Собрана в одном месте, чтобы после переезда удалить её одним коммитом:

- код — `app/Modules/Core/Transfer/` (команда, сервисы, контракт `CollationKeys`, конфиг `db_transfer.*` в `Transfer/config.php`, `TransferServiceProvider`); Core подключает его одной строкой в `CoreServiceProvider::register()`; код приложения инструмент не вызывает;
- тесты — `tests/Unit/Core/Transfer/*` (job `tests`), `tests/Feature/Core/Transfer/MysqlDataTransferTest` (workflow `MySQL data transfer`, необязательный);
- устройство, порядок переключения, откат, проверка и чек-лист удаления — [mysql-cutover.md](../guides/mysql-cutover.md).
- `--without-secrets` (2026-10-08) — режим тестового дампа (передача DevOps/третьим лицам, нет прод-`APP_KEY`): таблицы
  из `KeyCheck::ENCRYPTED` (сейчас `integration_secrets`) не копируются и остаются пустыми, `APP_KEY` не проверяется,
  `--verify` ожидает в них 0 строк, итог печатает пропущенные таблицы с числом строк источника. Правила — `WithoutSecrets`
  (unit `WithoutSecretsTest`), `KeyCheckTest` ловит новую модель с `encrypted`, не внесённую в список; feature —
  `MysqlDataTransferTest::test_without_secrets_needs_no_app_key_and_leaves_encrypted_tables_empty`. Без флага — прежний
  fail-closed. Для боевого cutover флаг запрещён — [mysql-cutover.md](../guides/mysql-cutover.md#тестовый-дамп-без-секретов---without-secrets).
- Проверка «цель ≠ рабочая БД приложения» по `@@server_uuid` больше не делает исключения для приложения на другом драйвере: приложение только на MySQL, несравнимое соединение — отказ (fail-closed); feature-тест запускает команду с приложением на соседней БД MySQL.
- Миграции данных после заморозки (2026-10-08, #183): `SchemaCheck::POST_FREEZE_DATA_MIGRATIONS` — список миграций
  `main`, которых нет на замороженном источнике переноса и которые не меняют схему (сейчас одна:
  `2026_10_28_100001_mark_sent_offer_touchpoints`, пометка писем отправленных офферов). Если цель уже мигрирована
  текущим релизом, такие миграции «лишние на цели» не считаются расхождением: preflight/`--verify` печатают строку
  `info`, а таблицы и колонки сравниваются строго, как раньше. После копирования команда снимает их с учёта в
  `migrations` цели (`SchemaCheck::requeuePostFreeze` → `SchemaInspector::forgetTargetMigrations`, единственная запись в
  эту таблицу) и печатает напоминание: `php artisan migrate --force` повторит их по перенесённым строкам (до копирования
  они отработали по пустой таблице). Миграцию со схемой в список не добавлять — это спрятало бы реальный дрейф. Тест —
  `MysqlDataTransferTest::test_post_freeze_data_migration_is_accepted_and_requeued_after_copy`.
- Устаревшие таблицы только в источнике (2026-10-08): `SchemaCheck::LEGACY_SOURCE_ONLY_TABLES` (сейчас `app_state` —
  остаток раннего прототипа на боевом источнике, на который нет ни одной ссылки в коде). Есть в источнике и нет на цели —
  строка `info` «устаревшая таблица только в источнике, не переносится: app_state (N строк)» в preflight и итоговой
  сверке, не `FAIL`; копирование и `--verify` её не трогают (`SchemaInspector::tables()` — пересечение сторон, число строк —
  `SchemaInspector::sourceRowCount`). Появилась и на цели — `FAIL`, список не маскирует дрейф. В список — только таблицы
  без ссылок в коде (unit `SchemaCheckTest` это проверяет). Правила и тесты —
  [mysql-cutover.md](../guides/mysql-cutover.md#допустимые-расхождения-схемы).
