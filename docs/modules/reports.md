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

**Отчёта «Гендерний розрив в оплаті» нет:** в SinHRM нет ни зарплат, ни пола сотрудников. Появится, только если эти
данные будут введены (и после решения владельца о правовой основе обработки).

## Как устроено
Бэкенд — `backend/app/Modules/Reports`, маршруты `/api/reports/{catalog,builder,saved}` (старые
`/api/reports/{touches,funnel,sources,reject-reasons,scripts}` остаются в Recruiting/Scripts).

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
| `channel_effectiveness` | recruiting | все (по филиалам) | `Recruiting\Services\ReportService::channels`: кандидаты периода по каналу привлечения → заявки → дошли до отбора → наняты, конверсия; расходы (пропорционально дням) и цена найма — только админам ([acquisition-channels.md](acquisition-channels.md)) |
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
`POST /api/reports/builder/csv`, `GET /saved/{id}/run?format=csv`.

### Фронтенд
`frontend/src/app/features/reports`: `catalog.page`, `report-view.page` (фильтры из query-параметров),
`builder.page` (`?saved=<id>`), `report-table` (таблица + CSS-полосы), `reports.service` (CSV — Blob → `saveBlob` из
`core/http/api-error.ts`), `reports.model` (`barPercent`, `columnMax`, `filterParams`, `cleanSpec`).

## Как проверить
`php artisan test --filter=Reports` — состав каталога по ролям (25 отчётов у админа, нет pay gap), область People и
филиалов, PII (`age`, колонки конструктора), белый список (422 на неизвестные колонки/наборы/операторы/агрегаты),
группировка, CSV (потоковый, `'=` / `'+` префиксы), сохранённые отчёты (приватность, повторный запуск), eNPS только по
закрытым волнам и с подавлением малых групп. `CsvTest` — экранирование ячеек.
