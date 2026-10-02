# Модуль Assets (активы компании)

## Что это и зачем
Ноутбуки, телефоны, пропуска — кому выдано, когда, в каком состоянии вернули. Реестр с уникальными инвентарными
номерами и полной историей выдач. При увольнении воркфлоу сам ставит HR задачу «Зібрати активи» со списком того, что
числится за человеком.

## Как пользоваться
- **Адміністрування → Активи** (`/admin/assets`): поиск по номеру/названию/серийному, фильтр статуса; «Новий актив»
  (номер, название, серийный, тип, стоимость, дата покупки; «Додати тип»); в строке — «Видати» (поиск сотрудника,
  дата, состояние), «Повернути» (статус после возврата: на складе / в ремонте / списан, дата, состояние), «На склад»
  (из ремонта), «Історія».
- **Профиль сотрудника → вкладка «Активи»**: что у человека сейчас и что было раньше (видят админ, сам сотрудник,
  руководители выше — как вкладку «Робота»).
- **Воркфлоу:** шаг «Зібрати активи» (`collect_assets`) в шаблоне офбординга ([workflows.md](workflows.md)).

## Как устроено
Бэкенд — `backend/app/Modules/Assets`, маршруты `/api/assets/*`; реестр — gate `assets-manage` = `PeopleScope::isAdmin`.

### Таблицы (`Database/Migrations/2026_10_05_500001_create_assets_tables.php`)
| Таблица | Колонки | Заметки |
|---|---|---|
| `asset_types` | `name (unique)` | |
| `assets` | `inventory_number (unique), serial?, name, type_id?, status (in_stock\|assigned\|repair\|written_off), cost?, purchased_at?, notes?, employee_id?` | `employee_id` — текущий держатель (денормализация открытой выдачи) |
| `asset_assignments` | `asset_id, employee_id, assigned_at, returned_at?, condition_out?, condition_in?, assigned_by?, returned_by?` | история; `returned_at = null` — у человека сейчас |

### Правила (`Services/AssetService`)
- Инвентарный номер уникален **без учёта регистра** (проверка сервиса, 422 `inventory_number_taken`) + уникальный
  индекс на гонки (нарушение индекса → тот же 422).
- Статус `assigned` ставится только выдачей (`POST /{id}/assign`), снимается только возвратом
  (`POST /{id}/return`). `status: assigned` в `POST /api/assets` и `PATCH /api/assets/{id}` отсекает уже
  `SaveAssetRequest` (допустимы только `in_stock|repair|written_off`) — обычная 422 валидации с ошибкой в поле `status`,
  а не код `status_via_assign`. Код 422 `status_via_assign` приходит, когда в `PATCH` передан любой `status` у актива,
  который сейчас выдан (снять `assigned` можно только возвратом).
- Выдать можно только `in_stock` (409 `not_in_stock`) и не уволенному (422); вернуть — только выданное (409
  `not_assigned`), не раньше даты выдачи (422). Выдача/возврат — в транзакции с `SELECT … FOR UPDATE` на строку актива.
- Удаления нет — `written_off` (история остаётся).

### API
`GET/POST /api/assets/types`, `PATCH /types/{id}`; `GET /api/assets?status=&type_id=&q=`, `POST /api/assets`,
`GET|PATCH /api/assets/{id}` (с историей), `POST /{id}/assign {employee_id, date?, condition?}`,
`POST /{id}/return {date?, condition?, status?}`; `GET /api/assets/employee/{id}` — история сотрудника (доступ
`PeopleContext::canSeeJob`, иначе 404).

### Действие воркфлоу `collect_assets` (`Workflows/CollectAssetsExecutor`)
Исполнитель живёт в модуле Assets и регистрируется тегом `WorkflowsServiceProvider::EXECUTORS_TAG` из
`AssetsServiceProvider` (Open/Closed: Workflows про активы не знает; в `StepAction` добавлен только case). Наследует
`TaskStepExecutor`: задача исполнителю шага (или HR), заголовок «<config.title или «Зібрати активи»>: INV-001 Ноутбук,
…» (обрезка до 255), ссылка `/people/<id>?tab=assets`, шаг ждёт закрытия задачи. Ничего не числится →
`skipped: no_assets`. Идемпотентно — ключ задачи `wf:<run step id>`.

### Фронтенд
`frontend/src/app/features/assets`: `assets.page` (`/admin/assets`), `employee-assets.tab` (вкладка профиля
`assets`), `assets.service`, `assets.model`.

**Интерфейс (2026-09-26):** Даты вводятся только выпадающим календарём Angular Material (формат дд.мм.рррр, неделя с понедельника; [core.md](core.md)), в API уходит прежний `YYYY-MM-DD` (`core/date/iso-date.ts`, без сдвига часового пояса): дата покупки и дата выдачи/возврата.

**Выдача (2026-09-29).** Держатель выбирается одним полем по имени (`<app-person-picker>`: аватар, должность, отдел) вместо пары «поиск + список»; в `POST /{id}/assign` уходит тот же `employee_id`. Подробнее — [people.md](people.md#выбор-человека-person-picker-2026-09-29).

**Вид (рестайл C «Маршрут», 2026-10-02).** Статус актива — пилюля `.app-pill` с маркером-формой (`ASSET_STATUS_TONE`: на складе ● good, выдан ○ info, ремонт ◆ warn, списан — пунктирный ○ neutral + зачёркнуто); инвентарный и серийный номера — моно; строки таблицы разделены «треком» 1.5px, hover строки. Тест вида — `features/assets/assets.restyle.spec.ts` (контракт стилей: только токены темы, без hex, линии 1.5px, без «бледности» через opacity).

### Общие хелперы Core (2026-10-02)
- «сегодня» по умолчанию (дата выдачи и возврата без `date`) — `Core\Support\UserTime::today()`: дата пользователя (Europe/Kyiv), а не UTC; отличие от прежнего `Carbon::today()` только с 00:00 до 02:00/03:00 по Киеву, когда в UTC ещё вчера;
- поиск `LIKE` экранирует `%`, `_` и сам символ экранирования через `Core\Support\Database\Like` (`ESCAPE '!'`, `Like::contains(…, Like::PORTABLE)`) (номер, название, серийный);
- gate `assets-manage` задаётся `ModuleServiceProvider::defineRoleGate(…, UserRole::hrStaff())`: активный superadmin, admin или hr_manager — тот же набор, что `PeopleScope::isAdmin` (модуль больше не импортирует `PeopleScope` ради gate);
- текущий пользователь в контроллерах — общий трейт `Core\Http\Concerns\ResolvesActor` вместо приватной копии `actor()`.

Поведение API не менялось, кроме ночной границы «сегодня» (пункт выше); подробности — [core.md](core.md), раздел «Общие хелперы модулей».

## Как проверить
`php artisan test --filter=Assets` — доступ, уникальность номера (в т.ч. регистр), выдача/возврат/повторная выдача и
история, запрет прямого `assigned`, доступ к вкладке по People, шаг `collect_assets` (одна задача со списком,
`no_assets` без активов, шаблон с этим действием сохраняется).

## Доступ к модулю

Ключ модуля `assets`. Суперадмин может выключить модуль для всей компании или скрыть его от части ролей на странице «Адміністрування → Модулі». По умолчанию: включён, роли — все роли (как и до появления выключателя). Выключенный модуль отвечает 403 `module_disabled`, его фоновые задачи пропускаются, данные не удаляются. Подробнее — [modules-access.md](modules-access.md).
