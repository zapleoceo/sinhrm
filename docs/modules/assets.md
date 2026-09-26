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
  (`POST /{id}/return`); прямое редактирование — 422 `status_via_assign`.
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

## Как проверить
`php artisan test --filter=Assets` — доступ, уникальность номера (в т.ч. регистр), выдача/возврат/повторная выдача и
история, запрет прямого `assigned`, доступ к вкладке по People, шаг `collect_assets` (одна задача со списком,
`no_assets` без активов, шаблон с этим действием сохраняется).
