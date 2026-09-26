# Модуль TimeOff (отпуска и отсутствия)

## Что это и зачем
Учёт отсутствий: отпуск, больничный, день за свой счёт и любые свои типы. Сотрудник видит, сколько дней отпуска у него
осталось, подаёт запрос (система сама считает рабочие дни без выходных и праздников), руководитель согласует в один клик,
а вся команда видит в календаре, кто когда отсутствует. На главной — «Відсутні сьогодні» и «Чекають мого погодження».

Главные правила простыми словами:
- **Баланс = сумма движений** в журнале: начисление (+), согласованный отпуск (−), отмена согласованного (+), ручная
  корректировка админом, сгорание остатка сверх лимита переноса 1 января.
- **Считаются только рабочие дни**: пн–пт минус праздники (общие и праздники филиала сотрудника). Можно взять полдня
  в первый или последний день.
- Нельзя подать запрос, пересекающийся с другим ожидающим или согласованным запросом того же человека.
- Нельзя уйти в минус по отпуску (ожидающие запросы тоже резервируют дни). Админ может разрешить минус флажком.
- Больничный и день за свой счёт по умолчанию **без лимита**: баланс не ведётся, показывается «использовано в этом году».
- Согласует руководитель (любой выше по оргструктуре) или админ. Свой запрос сам себе согласовать нельзя (кроме админа).
- Настройки: количество дней отпуска **на филиал** (`vacation_annual_days`, `sick_leave_annual_days`)
  = политика типа с `branch_id`; без политики филиала действует общая (по умолчанию отпуск 24 дня в год).

## Как пользоваться
Меню → раздел «Люди»:
- **Мої відсутності** (`/timeoff`) — баланс по типам, форма нового запроса (тип, «з»/«по», пів дня, коментар; количество
  рабочих дней и остаток считает сервер ещё до отправки), список своих запросов с кнопкой «Скасувати запит» (пока отпуск
  не начался).
- **Календар команди** (`/timeoff/calendar`) — месяц, строка на человека, цвет — тип отсутствия, штриховка — ждёт
  согласования, серым — выходные, жёлтым — праздники. Фильтр по филиалу. Видны: вы, ваши коллеги с тем же руководителем
  и все ниже вас; админ — все. Комментарии к запросам в календаре не показываются.
- **Погодження** (`/timeoff/approvals`) — запросы на отсутствие и на изменение данных ваших людей: «Погодити» / «Відхилити».
- **Профиль сотрудника → вкладка «Відсутності»** — баланс и запросы человека (себе, руководителю, админу); руководитель и
  админ могут подать запрос за сотрудника.
- **Адміністрування → Налаштування відсутностей** (`/admin/timeoff`, суперадмин и админ) — типы (оплачиваемый, с балансом,
  нужно ли согласование, цвет), политики (дней в год, начисление «щороку наперед» или «щомісяця», максимум переноса,
  филиал) и праздники по годам (общие или для филиала).

## Как устроено
Бэкенд — `backend/app/Modules/TimeOff`, маршруты `/api/timeoff/*` (`auth:sanctum` + `EnsureUserIsActive`). Права берутся из
модуля People (`PeopleScope`, [people.md](people.md)); запись настроек — gate `timeoff-manage` (superadmin, admin).

### Таблицы (миграции `Database/Migrations/2026_10_02_2000*`)
| Таблица | Колонки | Заметки |
|---|---|---|
| `leave_types` | `name, code (unique), paid, unit (days\|hours), color, requires_approval, tracks_balance, active` | data-миграция `…200002`: `vacation` (с балансом), `sick` и `day_off` (без лимита; `day_off` — неоплачиваемый) |
| `leave_policies` | `leave_type_id, branch_id? (null = общая), accrual_mode (yearly_upfront\|monthly), annual_days, carry_over_max? (null = переносится всё), active` | общая политика отпуска: 24 дня, `yearly_upfront` |
| `holidays` | `date, name, branch_id? (null = все филиалы)` | |
| `leave_balance_ledger` | `employee_id, leave_type_id, delta, reason (accrual\|request\|adjustment\|carry_over\|expiry), reference_id?, period?, comment?, created_by?, created_at` | только добавление; `unique(employee_id, leave_type_id, reason, period)` — идемпотентность начислений (`period` = `2026` или `2026-10`; у строк запросов `NULL`, они не конфликтуют) |
| `leave_requests` | `employee_id, leave_type_id, starts_on, ends_on, half_day (none\|start\|end), days, comment?, status (pending\|approved\|rejected\|cancelled), balance_override, approver_id?, decided_at?, decision_comment?, created_by?` | `days` считает сервер |

Единица `hours` у типа сейчас только справочная: запросы считаются в рабочих днях.

### Расчёты (чистые классы, без БД)
- `Support/WorkingDayCalculator::days(from, to, halfDay, holidays)` — пн–пт минус праздники; `start`/`end` снимают 0.5 с
  первого/последнего дня, если он рабочий; однодневный полудневный запрос = 0.5; период длиннее 366 дней — исключение
  (`InvalidArgumentException`), а не молча обрезанный подсчёт.
- `Support/AccrualCalculator` — `yearly_upfront`: вся годовая норма в период `YYYY`, в год приёма — пропорционально
  месяцам (месяц приёма считается): приняли в октябре при 24 днях → 6; `monthly`: в месяц (`YYYY-MM`) начисляется накопленная цель года
  `round(annual × отработанные_месяцы / 12, 2)` минус уже начисленное в этом году — 12 начислений дают ровно годовую норму
  (20 дней → 20.00, без дрейфа 20.04), а первый запуск в году догоняет прошедшие месяцы этого года; `expiring(balance, carryMax)` — сколько сгорает.

### Правила запросов (`Services/LeaveRequestService`)
**Гонки.** Создание, согласование и отмена берут блокировку строки сотрудника
(`EmployeeRepository::lockForUpdate` → `SELECT … FOR UPDATE` в транзакции): проверки пересечения и баланса — «прочитал,
потом записал», и два параллельных запроса одного сотрудника выполняются по очереди (на Postgres; в SQLite-тестах путь
блокировки проверяется через контракт репозитория). Исключающее ограничение БД не добавлялось — блокировки достаточно и
она переносима.
Создание: тип активен (`inactive_type`), период ≤ 366 дней (`range_too_long`), рабочих дней > 0 (`no_working_days`),
нет пересечения с `pending/approved` того же сотрудника (`overlap`), для типа с балансом `баланс − другие ожидающие ≥ дней`
(`insufficient_balance {available, requested}`; флаг `override_balance` учитывается только у админа и сохраняется в
запросе). Тип без согласования (`requires_approval = false`) сразу `approved`. Всё в транзакции.
Согласование: `admin` или руководитель выше; повторная проверка баланса; смена статуса — compare-and-set
(`pending → approved`, иначе 409 `invalid_status`), в журнал `−days` (`reason=request`, `reference_id`).
Отклонение — только из `pending`. Отмена: сотрудник — ожидающий или ещё не начавшийся согласованный; руководитель/админ —
любой ожидающий/согласованный; отмена согласованного возвращает `+days` в журнал.

### Начисление (`Services/AccrualService`, фоновая задача `timeoff.accrue`)
`Services/AccrualJob` зарегистрирован как `Core\Contracts\ScheduledJob` — cron каждые 30 минут через
`POST /api/ops/jobs/run` ([core.md](core.md)). Для каждого неуволенного сотрудника и каждого активного типа с балансом:
политика филиала или общая → начисление текущего периода, если его ещё нет; в первый проход года остаток прошлых лет сверх
`carry_over_max` списывается строкой `expiry`. Повторный/параллельный запуск ничего не дублирует (проверка + уникальный
индекс; вставка внутри savepoint, чтобы на Postgres не ломать транзакцию). Пропущенные месяцы текущего года догоняются следующим
запуском (накопленная цель); прошлые годы — нет. Ответ: `{employees, accrued, expired}`. При создании сотрудника (`People\Events\EmployeeHired`) слушатель
`Listeners/GrantAccrualOnHire` сразу начисляет текущий период (пропорционально).

### Эндпоинты `/api/timeoff`
| Метод и путь | Кто | Параметры / тело | Ответ |
|---|---|---|---|
| `GET types` | любой активный | `all=1` (с выключенными — только админ) | список |
| `POST types`, `PATCH types/{id}` | admin | `{name, code (a-z0-9_), paid, unit, color (#rrggbb), requires_approval, tracks_balance, active}` | 201 / 200 |
| `GET policies`, `POST policies`, `PATCH policies/{id}` | admin | `{leave_type_id, branch_id?, accrual_mode, annual_days 0..366, carry_over_max?, active}` | список / 201 / 200 |
| `GET holidays` | любой активный | `year?, branch_id?` (строки ок) | по дате |
| `POST holidays`, `PATCH holidays/{id}`, `DELETE holidays/{id}` | admin | `{date Y-m-d, name, branch_id?}` | 201 / 200 / 204 |
| `GET balances` | сам; `employee_id` — admin или руководитель выше (иначе 403) | `employee_id?` | `[{leave_type, tracked, balance, pending, available, used_this_year, policy}]`, `meta.employee`; нет записи сотрудника → 404 `no_employee` |
| `GET balances/history` | как выше | `employee_id?, leave_type_id?` | последние 100 строк журнала |
| `POST balances/adjust` | admin | `{employee_id, leave_type_id (с балансом), delta ≠ 0, comment?}` | 201, новые балансы |
| `GET requests` | любой активный | `employee_id?, status?, leave_type_id?, perPage` | admin — все; остальные — свои и людей ниже; `can_decide`, `can_cancel` в строке |
| `GET requests/preview` | как создание | те же поля, что у создания | `{days, holidays[], tracked, available, sufficient, overlap}` |
| `POST requests` | сам; за другого — admin или руководитель выше | `{leave_type_id, starts_on, ends_on, half_day?, comment?, employee_id?, override_balance?}` | 201; ошибки — см. «Правила» |
| `GET requests/{id}` | кто видит «работу» сотрудника | — | запрос |
| `POST requests/{id}/approve\|reject\|cancel` | см. «Правила» | `{comment?}` | 200 / 403 `forbidden` / 409 `invalid_status` / 422 `insufficient_balance` |
| `GET approvals` | руководитель, admin | — | ожидающие, которые вы можете решить (свои исключены), старые сверху |
| `GET calendar` | любой активный | `from, to` (по умолч. текущий месяц, ≤ 62 дня), `branch_id?` | `{absences[{employee, leave_type, starts_on, ends_on, half_day, status}], holidays[]}` — только `approved` и `pending` |

Главная страница: `TimeOffDashboardSection` (контракт `Overview\Contracts\DashboardSection`, [overview.md](overview.md)) →
`data.timeoff = {out_today[], my_approvals: {count, items[≤5]}}`.

### Слои
`Http/Controllers` (`SettingsController`, `BalanceController`, `LeaveRequestController`) → `Http/Requests` → `Services`
(`LeaveSettingsService`, `BalanceService`, `LeaveRequestService`, `CalendarService`, `AccrualService`, `EmployeeResolver`)
→ `Contracts` (`LeaveSettingsRepository`, `LedgerRepository`, `LeaveRequestRepository`) → `Repositories/Eloquent*`.
Ошибки — `Exceptions/TimeOffException`. Даты в запросах сравниваются через `whereDate` (SQLite хранит `date` как
`Y-m-d H:i:s`, Postgres — как дату).

### Фронтенд (`frontend/src/app/features/timeoff`)
| Файл | Что |
|---|---|
| `timeoff.model.ts`, `timeoff.service.ts` | типы, HTTP, `timeoffErrorKey` |
| `timeoff.dates.ts` | даты `YYYY-MM-DD` на UTC-полночах: месяц, сдвиг, выходные, раскладка отсутствий по дням, оценка дней до ответа сервера |
| `leave-requests.store.ts` | список запросов + действия, `version` для перезагрузки балансов |
| `widgets/` | `BalancesPanel`, `RequestsList`, `LeaveRequestForm` (нативные `type="date"`, превью с сервера с задержкой 300 мс) |
| `my/`, `calendar/`, `approvals/`, `settings/` | страницы `/timeoff`, `/timeoff/calendar` (CSS grid, без библиотек), `/timeoff/approvals`, `/admin/timeoff` |

Строки — `timeoff.*` в `public/i18n/{uk,ru,en}.json`.

## Как проверить
Бэкенд: `tests/Feature/TimeOff/LeaveRequestApiTest` (401, выходные/праздники/полдня в превью и при создании, праздник
чужого филиала не учитывается, баланс и резерв ожидающими, override только у админа, тип без лимита без журнала,
пересечения, согласование → журнал −дни, отмена → +дни, кто может согласовать/отменить, начавшийся отпуск отменяет только
решающий, тип без согласования, подача за другого, области списков/входящих/балансов/календаря, блок на главной),
`SettingsApiTest` (данные по умолчанию, типы/политики/праздники, политика филиала поверх общей, корректировки),
`ConcurrencyGuardsTest` (блокировка строки сотрудника в транзакции при создании/согласовании/отмене, повторный
одинаковый запрос → один, второе согласование видит баланс после первого),
`AccrualJobTest` (через `POST /api/ops/jobs/run`: год наперёд идемпотентно и пропорционально, помесячно, 12 помесячных
запусков = ровно годовая норма, сгорание 1 января,
без политики — ничего). Unit: `WorkingDayCalculatorTest`, `AccrualCalculatorTest`, `LeaveServicesTest`.
Фронт: `timeoff.spec.ts`.

## Ограничения и следующие шаги
- Статус сотрудника `on_leave` не ставится автоматически по согласованному отпуску (меняет админ).
- Балансы ведутся только здесь (внешнего импорта нет). Нет уведомлений (почта/Telegram) о новых запросах; нет «задонатить отпуск».
- Праздники вводятся вручную (государственный календарь не подгружается).
