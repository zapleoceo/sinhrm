# Модуль Directory (справочники)

## Что это и зачем
Справочники компании: **филиалы, города, отделы и должности**. На них опираются все остальные разделы:
у вакансии будет филиал и должность, у рекрутера — филиалы, в которых он работает. Справочники
ведутся **только вручную** — внешнего импорта нет (решение владельца: интеграции с внешними HR-системами не будет).

Главные правила:
- **Ничего не удаляется.** Ненужную запись выключают (статус «Вимкнено»), история не ломается.
- **Реальные справочники в код не попадают** (репозиторий публичный): они живут только в базе.
  Заполняются вручную. В тестах — только синтетика из фабрик.
- **Сами справочники открыты для чтения любому активному пользователю** (это справочные данные: ими заполняются
  фильтры и формы, рекрутеру нужно видеть все филиалы, чтобы выбрать нужный). Филиалы на чтение справочников не влияют.
- **Филиалы ограничат рабочие данные.** Контракт `AccessibleBranches` — то, через что будущий модуль Recruiting
  будет отбирать кандидатов и вакансии: рекрутер и наблюдатель — только своих филиалов, суперадмин и админ — все.

## Как пользоваться
Меню слева → «Адміністрування → Довідники» (видно суперадмину и админу).
- Вкладки: Філії, Міста, Відділи, Посади. Поиск по названию, фильтр по статусу, постраничный вывод.
- **Додати** — новая запись по названию. **✎** — переименовать прямо в строке (Enter — сохранить, Esc — отмена).
  **Вимкнути / Увімкнути** — кнопка в строке. Изменения видны сразу; если сервер отказал — строка возвращается как была.
- Филиалы пользователя — в «Адміністрування → Користувачі», колонка «Філії» (см. [users.md](users.md)).

## Как устроено
### Таблицы (миграции `Database/Migrations/2026_09_26_120001_create_directory_tables.php`, `2026_10_07_100001_drop_directory_external_ids.php`)
| Таблица | Колонки | Заметки |
|---|---|---|
| `cities`, `departments`, `positions` | `id, name, status (active\|disabled), timestamps` | индекс `(status, name)` |
| `branches` | те же + `city_id` (fk `cities`, null on delete) | |
| `branch_user` | `id, user_id (fk users, cascade), branch_id (fk branches, cascade), timestamps`, `unique(user_id, branch_id)` | филиалы пользователя |

Колонка `external_id` (ключ внешнего импорта) удалена миграцией `2026_10_07_100001_drop_directory_external_ids`.
Модели: `Models/DictionaryItem` (общая база) → `Branch` (+ `city()`), `City`, `Department`, `Position`.
Enum `Enums/DirectoryStatus` (`active`, `disabled`), `Enums/DictionaryType` (`branches|cities|departments|positions` →
модель). У `App\Models\User` связь `branches()` (через `branch_user`).

### Доступ
| Действие | Кто | Как проверяется |
|---|---|---|
| Чтение `GET /api/directory/{type}` | любой **активный** пользователь | `auth:sanctum` + `EnsureUserIsActive` |
| Создание / правка / выключение | активный `superadmin` или `admin` | Gate `manage-directory` (`Providers\DirectoryServiceProvider::MANAGE_DIRECTORY`) |

Гость → 401, заблокированный или без права → 403, неизвестный справочник → 404.

### Эндпоинты (`/api/directory`)
| Метод и путь | Тело / параметры | Ответ |
|---|---|---|
| `GET /{type}` | `q` (по названию, без учёта регистра), `status` (`active\|disabled`), `perPage` 1..200 (строка `"50"` тоже принимается, по умолчанию 50), `page` | `{data: [item], links, meta}`, сортировка по названию |
| `POST /{type}` | `{name, status?, city_id?}` | 201 `{data: item}`; 422 при ошибке полей |
| `PATCH /{type}/{id}` | `{name?, status?, city_id?}` | `{data: item}`; нет записи в этом справочнике → 404 |

`item` (`Http/Resources/DictionaryItemResource`): `id, name, status, created_at, updated_at`;
у филиала ещё `city_id` и `city: {id, name} | null`. `city_id` принимается только для `branches` (иначе 422) и только id существующего **активного** города. `DELETE` нет (405).

### Как работает ограничение по филиалам
Контракт `Contracts/AccessibleBranches` (реализация `Services/BranchAccess`), метод `for(User): ?list<int>`:
- активный `superadmin` / `admin` → `null` — без ограничений;
- `recruiter` / `viewer` → id **активных** филиалов из `branch_user` (выключенный филиал доступ не даёт);
- заблокированный пользователь или пользователь без филиалов → `[]` — не видит ничего.

Как использовать в будущих запросах (модуль Recruiting):
```php
$ids = $accessibleBranches->for($user);
if ($ids !== null) {
    $query->whereIn('branch_id', $ids);   // [] → пустой результат
}
```
Назначение филиалов — `PATCH /api/users/{id}` с `branch_ids` (модуль Users).

### Слои
`Http/Controllers/DirectoryController` → `Http/Requests` (`ListDictionaryRequest`, `SaveDictionaryItemRequest`) →
`Services/DirectoryService` → `Contracts/DictionaryRepository` (`Repositories/EloquentDictionaryRepository`).
Демо- и тестовые данные — фабрики `Database/Factories/*Factory` (синтетические названия).

### Фронтенд (`features/directory`)
`directory.page.ts` — вкладки, поиск, фильтр статуса, таблица с переименованием в строке, «Додати». `directory.store.ts` — состояние страницы
на signals (переименование и выключение — оптимистично с откатом, устаревшие ответы после смены вкладки
игнорируются). `directory.service.ts` — HTTP, `active(type)` для выпадающих списков (используется на странице
пользователей), перевод кодов ошибок в ключи i18n `directory.errors.*`. Маршрут `/admin/directory` —
`roleGuard('superadmin', 'admin')`.

## Как проверить
Тесты: `tests/Feature/Directory/DirectoryApiTest.php` (401/403/404, чтение любым активным, сортировка, фильтры,
`perPage` строкой и границы, создание/правка/выключение админом, `city_id` только у филиалов, запрет записи
рекрутеру/наблюдателю, нет DELETE, `POST /api/directory/import` → 404), `tests/Unit/Directory/*` (`BranchAccess`, `DirectoryService`).
Фронт: `directory.service.spec.ts`, `directory.store.spec.ts`.

Вручную (нужна сессия в браузере):
```bash
curl -i "https://sinhrm.vercel.app/api/directory/branches?perPage=20"   # без сессии → 401
```
