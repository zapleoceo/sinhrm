# Модуль Reports (каталог отчётов и конструктор)

## Что это и зачем
Готовые отчёты по всем модулям в одном каталоге — численность, текучесть, стаж, отпуска, воронка найма, время до
найма, эффективность каналов привлечения, часы и табели, OKR, eNPS, настроение, SLA обращений, активы — плюс **конструктор**: выбрать набор данных, колонки, фильтры,
группировку и выгрузить в CSV. Каждый видит только те отчёты и данные, на которые у него уже есть права: руководитель —
свою команду, рекрутер — свои филиалы, персональные данные — только админы. Свои варианты можно сохранить.

## Как пользоваться
- **Сервіси → Каталог звітів** (`/reports/catalog`): группы «Загальні», «Core HR», «Продуктивність», «Рекрутинг»;
  справа — «Збережені звіти» (открыть, CSV, удалить). Старая страница рекрутинга `/reports` осталась как была.
- Отчёт (`/reports/catalog/:key?from=&to=&branch_id=&weeks=&period=`): фильтры отчёта, диаграмма-полосы на CSS и
  таблица, «CSV», «Зберегти». «—» в ячейке — значение скрыто (группа меньше минимума).
- **Конструктор** (`/reports/builder`): набор данных → колонки (🔒 — персональные, только админам) → фильтры
  (`= ≠ > ≥ < ≤ містить`) → «Групувати за» + количество/сумма/среднее → «Побудувати», «CSV», «Зберегти».

**«Розрив в оплаті» (2026-09-27):** считается по компенсации и необязательному полю «стать» из People (оба видит только HR);
группы меньше 5 человек скрыты — см. ниже.

## Как устроено
Бэкенд — `backend/app/Modules/Reports`, маршруты `/api/reports/{catalog,builder,saved}` (старые
`/api/reports/{touches,funnel,sources,reject-reasons,scripts}` остаются в Recruiting/Scripts).

Маршруты (`routes.php`, `auth:sanctum` + `EnsureUserIsActive`; доступность решает каждый отчёт/набор):

| Метод и путь `/api/reports/…` | Что |
|---|---|
| `GET catalog` | каталог отчётов, доступных пользователю (`ReportsController::index`) |
| `GET catalog/{key}` (`[a-z_]+`) | запуск отчёта с фильтрами из query (`run`); недоступный/неизвестный — 404 |
| `GET catalog/{key}/csv` | то же в CSV |
| `GET builder/datasets` | наборы конструктора, доступные пользователю, с колонками (`datasets`) |
| `POST builder/run`, `POST builder/csv` | запуск конструктора / CSV |
| `GET/POST saved`, `PUT/DELETE saved/{id}`, `GET saved/{id}/run` | сохранённые отчёты (см. ниже) |

«Админ» в этой странице — `PeopleScope::isAdmin` (`UserRole::hrStaff()`: superadmin, admin, hr_manager).

### Реестр отчётов (Open/Closed)
`Contracts/ReportDefinition`: `key`, `group (general|hr|performance|recruiting)`, `filters()` (схема: `from`, `to`,
`branch_id`, `weeks`, `period`), `columns()` (`{key, type: string|number|percent|date}`), `chart()` (колонки полос),
`available(ScopedContext)`, `rows(ScopedContext, filters)`. Классы в `Definitions/*` тегируются в
`ReportsServiceProvider` (`reports.definitions`); `Support/ReportRegistry` — по ключу (дубли ключей — ошибка).
Фильтры валидирует `ReportCatalogService` (только объявленные отчётом; `to ≥ from`); недоступный отчёт — 404.

**`ScopedContext`** (`Services/ScopedContextFactory`) собирается из существующих моделей доступа и никогда их не
расширяет: `PeopleScope::for` (админ — все, руководитель — поддерево + сам, остальные — сам) и
`RecruitingScope::for` (`AccessibleBranches`: админ — все филиалы, остальные — свои).

| Ключ | Группа | Кому | Откуда данные (DRY) |
|---|---|---|---|
| `headcount` | hr | админ, руководитель | `QueryReportDataRepository::employees` (работающие на дату) |
| `hires_terminations` | hr | админ, руководитель | employees: `hired_at` / `fired_at` по месяцам |
| `turnover` | hr | админ, руководитель | увольнения ÷ средняя численность (начало+конец месяца)/2; строка `total` |
| `tenure` | hr | админ, руководитель | <1, 1–3, 3–5, 5+ лет |
| `age` | hr | **только админ** (дата рождения — PII) | корзины <25…55+, `unknown`; только количества |
| `leave_usage` | hr | админ, руководитель | согласованные `leave_requests`, пересекающие период |
| `absences_summary` | hr | админ, руководитель | люди и дни по месяцу **начала** запроса |
| `leave_balances` | hr | админ, руководитель | сумма `leave_balance_ledger` по типам с балансом, ≤ 2000 строк |
| `desk_sla` | general | админ | `desk_cases` + `Desk\Support\Sla` (та же формула) |
| `assets_by_status` | general | админ | `assets` по статусу и типу, сумма стоимости |
| `recruiting_funnel` | recruiting | все (по филиалам) | `Recruiting\Services\ReportService::funnel`, сумма по этапам |
| `time_to_hire` | recruiting | все (по филиалам) | нанятые заявки, закрытые в периоде: среднее и медиана дней |
| `source_effectiveness` | recruiting | все (по филиалам) | `ReportService::sources` + % найма |
| `reject_reasons` | recruiting | все (по филиалам) | `ReportService::rejectReasons` |
| `recruiter_touches` | recruiting | все (по филиалам) | `ReportService::touches` (рекрутер × канал, из SinHRM) |
| `script_scores` | recruiting | все (по филиалам) | `Scripts\Services\ScriptReportService::report` |
| `okr_progress` | performance | админ, руководитель | `objectives`: руководителю — только личные/командные цели своих людей; только числа |
| `review_completion` | performance | админ, руководитель | `review_assignments`: назначено/заполнено по циклам (без оценок) |
| `enps_trend` | performance | админ | **только закрытые** волны с вопросом eNPS; `Pulse\Contracts\ResponseRepository::answersOf` + `Pulse\Support\Enps`; ответов меньше `min_group_size` — `enps` и `responses` = `null` |
| `mood_trend` | performance | админ, руководитель | `Pulse\Services\MoodService::team` (только завершённые недели, группы меньше минимума скрыты, комментарии не выводятся) |
| `channel_effectiveness` | recruiting | все (по филиалам) | `Recruiting\Services\ReportService::channels`: кандидаты периода по каналу привлечения → заявки → дошли до отбора → наняты, конверсия; расходы (пропорционально дням) и цена найма — только superadmin/admin (`RecruitingScope::canManage`); `hr_manager` видит отчёт, но `cost` и `cost_per_hire` = `null` ([acquisition-channels.md](acquisition-channels.md)) |
| `time_by_employee` | hr | админ, руководитель | `Time\Services\TimeReportService::weekly` (целые недели, ≤ 26): очікувано / відпрацьовано / понаднормово / бракує / відсутності по сотруднику ([time.md](time.md)) |
| `time_by_department` | hr | админ, руководитель | те же недели, сумма по отделу |
| `time_overtime` | hr | админ, руководитель | недели со сверхурочными (сотрудник × неделя) |
| `time_missing` | hr | админ, руководитель | не отправленные недели с недоработкой (отпуска и праздники не считаются) |

«Рекрутинговые» отчёты доступны любому активному пользователю, как и прежняя страница `/reports`, — данные ограничены
его филиалами (у пользователя без филиалов — пусто).

### Конструктор (`Services/BuilderService`, `Repositories/QueryBuilderRepository`)
Наборы — `Datasets/*` (тег `reports.datasets`): **белый список** колонок `ключ → фиксированное SQL-выражение, тип,
pii`. Пользователь присылает только ключи: неизвестный набор/колонка — 422 `unknown_dataset`/`unknown_column`,
PII не админу — 422 `pii_forbidden`, оператор вне `eq|neq|gt|gte|lt|lte|contains` — 422, `sum/avg` не по числу — 422,
больше 20 колонок или 10 фильтров — 422. Значения фильтров — только привязанные параметры; даты сравниваются по дню;
`contains` — `lower(…) like … escape '!'`. Группировка: `group_by` + `count|sum|avg` → колонки `[<group_by>, value]`.
Лимит 5000 строк (`truncated`).

| Набор | Кому | Область (как в модуле-источнике) | PII (только админ) |
|---|---|---|---|
| `employees` | админ, руководитель | `PeopleContext::visibleIds` | `birth_date`, `personal_email` |
| `leave_requests` | админ, руководитель | те же сотрудники | — (комментарии не выводятся) |
| `applications` | все | вакансии своих филиалов | `candidate_email`, `candidate_phone` |
| `touchpoints` | все | касания своих филиалов или свои | — (тексты сообщений не выводятся) |
| `assets` | админ | весь реестр | — |

### Сохранённые отчёты
Таблица `saved_reports` (`user_id, name, kind (builder|catalog), definition jsonb`; миграция
`2026_10_05_600001_create_saved_reports_table.php`). Определение проверяется при сохранении (builder — белым списком,
catalog — доступностью отчёта, лишние фильтры отбрасываются) и **снова при запуске под текущими правами владельца** —
сохранённый отчёт не сохраняет доступ, который у пользователя отобрали. Чужой — 404. Не больше 100 на пользователя.
API: `GET/POST /api/reports/saved`, `PUT/DELETE /saved/{id}`, `GET /saved/{id}/run[?format=csv]`.

### CSV (`Support/Csv`, `Http/Resources/CsvResponse`)
`StreamedResponse` (`fputcsv` в `php://output`), UTF-8 BOM (Excel и кириллица), `Content-Disposition: attachment`,
`no-store`. **Защита от CSV/formula injection (OWASP):** текстовая ячейка, начинающаяся с `=`, `+`, `-`, `@` (а
также табуляции и `\r`), получает префикс `'`; числа не трогаются. Эндпоинты: `GET /api/reports/catalog/{key}/csv`,
`POST /api/reports/builder/csv`, `GET /saved/{id}/run?format=csv`. Последняя строка CSV — итог, если он есть: первая
ячейка всегда с меткой (`Total` или `Total: <сумма>`, если первая колонка суммируется), «—» у колонок без итога; защита
та же. В UI метка «Разом» тоже всегда в первой ячейке.

### Строка «Разом» (`Support/Totals`)
Итог считает бэкенд и отдаёт в `totals` (`run`, `builder/run`, `saved/{id}/run`; `null` при < 2 строк), UI рисует его
`<tfoot>` в `report-table` (жирный, верхняя граница, токены светлой/тёмной темы). Каждая числовая колонка отчёта
объявляет `total` осознанно: `sum` (количества, часы, дни, деньги), `ratio` + `of: [числитель, знаменатель]`
(истинная конверсия — например `hire_rate_pct` = Σhired / Σcandidates, `cost_per_hire` = Σcost / Σhired),
`avg-weighted` + `weight` (среднее, взвешенное по колонке количества: `avg_days` по `hires`, `avg_score` по
`evaluations`), `none` (по умолчанию: медианы, проценты без знаменателя в данных, неаддитивные — сотрудники с
отсутствиями по месяцам, `avg_headcount`, респонденты по неделям). **Анонимность:** `null` в ячейке (скрытая группа)
делает итог колонки `null` — скрытое значение не вычислить как «итог минус видимые». В `gender_pay_gap` скрытые
группы выпадают строками целиком, поэтому `employees` — `none`. Конструктор: сгруппированный `value` — `sum` для
count/sum, `none` для avg; сырые строки — только колонки датасета с `total` (`assets.cost`, `leave_requests.days`);
при обрезке по лимиту итога нет.

### Фронтенд
`frontend/src/app/features/reports`: `catalog.page`, `report-view.page` (фильтры из query-параметров),
`builder.page` (`?saved=<id>`), `report-table` (таблица + CSS-полосы), `reports.service` (CSV — Blob → `saveBlob` из
`core/http/api-error.ts`), `reports.model` (`barPercent`, `columnMax`, `filterParams`, `cleanSpec`).

**Интерфейс (2026-09-26):** Даты вводятся только выпадающим календарём Angular Material (формат дд.мм.рррр, неделя с понедельника; [core.md](core.md)), в API уходит прежний `YYYY-MM-DD` (`core/date/iso-date.ts`, без сдвига часового пояса): фильтры «з» / «по» отчётов каталога.

**Розрив в оплаті (`gender_pay_gap`, 2026-09-27):** только админы (HR). Берёт текущую компенсацию работающих сотрудников с
указанным полом (People), группирует по валюте и периоду (без пересчёта курсов), считает медиану по полу и разрыв к медиане мужчин
в %. Группа меньше 5 человек скрыта (`GenderPayGapReport::MIN_GROUP`), разрыв без обеих групп не считается. Фильтр: филиал.

**Вид (рестайл C «Маршрут», 2026-10-02).** Каталог — группа отчётов как ветка: станции-кольца на одной линии, ссылки 44px на телефоне. Таблица отчёта — шапка-подпись, строки на «треке», числа моно, строка «Разом» — на тихой заливке с линией сверху. Диаграмма — линии рампы графиков, прорисовываются один раз («трасса», 600мс, transform), при `prefers-reduced-motion` статичны. Тест вида — `features/reports/reports.restyle.spec.ts` (контракт стилей: только токены темы, без hex, линии 1.5px, без «бледности» через opacity).

### Общие хелперы Core (2026-10-02)
- `Csv` (защита от формул) перенесён из `Reports\Support` в `Core\Support\Export\Csv`, его же использует выгрузка People; `Content-Disposition` CSV — `Core\Http\Responses\Download::disposition()`;
- поиск `LIKE` экранирует `%`, `_` и сам символ экранирования через `Core\Support\Database\Like` (`ESCAPE '!'`, `Like::contains(…, Like::PORTABLE)`) (оператор `contains` конструктора).

Поведение API не менялось; подробности — [core.md](core.md), раздел «Общие хелперы модулей».

### Сортировка и фильтры в заголовках (2026-10-02)
Клик по названию колонки сортирует (повторный — в обратную сторону), воронка рядом — фильтр колонки; общий компонент `core/ui/table` (клиентская таблица `ClientTable`: все строки уже пришли, сравнение строк по языку интерфейса, пустые — в конце). Состояние — в адресе страницы с префиксом таблицы, ссылкой можно поделиться. Подключение — [guides/tables.md](../guides/tables.md). Открытый фильтр колонки объявляет число показанных строк — «Знайдено: N» (`appTableSortCount` = `rows().length`, с 2026-10-03).
- `report-table.ts` (каталог и конструктор): каждая колонка из описания отчёта сортирует; фильтр по типу колонки — текст «містить», числа и проценты — диапазон «від–до», даты — диапазон дат. Адрес — `r_sort`, `r_dir`, `r_<колонка>` (`r_<колонка>_from/_to`); фильтры отчёта сверху (`from`, `to`, `branch_id`…) по-прежнему уходят в API и не тронуты.
- Строка «Разом» — итог бэкенда по всему отчёту: остаётся внизу (`tfoot`) и в сортировке не участвует; при включённом фильтре колонки подпись — «Разом (усі рядки звіту)», чтобы итог не принимали за сумму видимых строк. Диаграмма над таблицей идёт в порядке таблицы. Фильтр, скрывший все строки, — «Немає рядків за цим фільтром».
- Тесты: `reports.spec.ts` (клик → `r_sort` и порядок, пустые в конце, «Разом» на месте, адрес → фильтр).

### Общие примитивы фронта
Общий код фронта лежит в `frontend/src/app/core` ([core.md](core.md)); фича его только вызывает.
- Ошибки API → i18n-ключ: `reportsErrorKey` — обёртка над общим `apiErrorKey` (`core/api/api-error.ts`) со своими кодами, списком статусов и запасным ключом; набор ключей и тексты прежние.
- Короткие уведомления (toast) — `NotifyService.show(key, { params?, duration? })` из `core/ui/notify.service.ts` вместо своего `toast()` с `MatSnackBar`; тексты, длительности и доступность (вежливая live-область snack bar) прежние.
- HTTP-сервис фичи снимает обёртку ответа `{ data }` общим оператором `unwrapData()` (`core/api/unwrap-data.ts`, тип `DataEnvelope<T>` из `core/api/api.model.ts`) вместо своего `map((r) => r.data)`; параметры запроса без пустых значений — `toParams` из `core/api/http-params.ts`, страница списка — `Paged<T>` оттуда же. Контракт API не менялся.

## Как проверить
`php artisan test --filter=Reports` — состав каталога по ролям (26 отчётов у админа, включая `gender_pay_gap`), область People и
филиалов, PII (`age`, колонки конструктора), белый список (422 на неизвестные колонки/наборы/операторы/агрегаты),
группировка, CSV (потоковый, `'=` / `'+` префиксы), сохранённые отчёты (приватность, повторный запуск), eNPS только по
закрытым волнам и с подавлением малых групп. `CsvTest` — экранирование ячеек.

## Доступ к модулю

Ключ модуля `reports`. Суперадмин может выключить модуль для всей компании или скрыть его от части ролей на странице «Адміністрування → Модулі». По умолчанию: включён, роли — все роли (как и до появления выключателя). Выключенный модуль отвечает 403 `module_disabled`, его фоновые задачи пропускаются, данные не удаляются. Подробнее — [modules-access.md](modules-access.md).

## Приёмка после бизнес-переходов API
Сквозной `HiringApiAcceptanceTest` сверяет точные catalog `recruiting_funnel`/`reject_reasons` rows
с HTTP-переходами hire/reject и исходными Recruiting reports. Чужой филиал не попадает в scoped результат;
admin aggregate включает контрольную группу, следующий период пуст, неправильный range возвращает 422.
Это DB/HTTP acceptance существующих контрактов, не UI/live-provider или concurrency доказательство.
