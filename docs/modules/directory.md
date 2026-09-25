# Модуль Directory (справочники)

## Что это и зачем
Справочники компании: **филиалы, города, отделы и должности**. На них опираются все остальные разделы:
у вакансии будет филиал и должность, у рекрутера — филиалы, в которых он работает. Структура повторяет
справочники Sintegrum (`branch`, `city`, `department`, `job`), чтобы данные можно было перенести оттуда.

Главные правила:
- **Ничего не удаляется.** Ненужную запись выключают (статус «Вимкнено»), история не ломается.
- **Реальные справочники в код не попадают** (репозиторий публичный): они живут только в базе.
  Заполняются вручную или импортом из Sintegrum. В тестах — только синтетика из фабрик.
- **Филиалы ограничивают доступ.** Рекрутер и наблюдатель будут видеть данные только своих филиалов;
  суперадмин и админ — всё.

## Как пользоваться
Меню слева → «Адміністрування → Довідники» (видно суперадмину и админу).
- Вкладки: Філії, Міста, Відділи, Посади. Поиск по названию, фильтр по статусу, постраничный вывод.
- **Додати** — новая запись по названию. **✎** — переименовать прямо в строке (Enter — сохранить, Esc — отмена).
  **Вимкнути / Увімкнути** — кнопка в строке. Изменения видны сразу; если сервер отказал — строка возвращается как была.
- Метка **S** у записи — пришла из Sintegrum (есть `external_id`).
- **Імпорт із Sintegrum** (только суперадмин) — забирает все четыре справочника и показывает, сколько записей
  новых / обновлено / без изменений. Если интеграция Sintegrum API не настроена — понятная ошибка и ссылка на
  «Інтеграції», где нужно задать адрес API и токен.
- Филиалы пользователя — в «Адміністрування → Користувачі», колонка «Філії» (см. [users.md](users.md)).

## Как устроено
### Таблицы (миграция `Database/Migrations/2026_09_26_120001_create_directory_tables.php`)
| Таблица | Колонки | Заметки |
|---|---|---|
| `cities`, `departments`, `positions` | `id, external_id (string 64, null, unique), name, status (active\|disabled), timestamps` | индекс `(status, name)` |
| `branches` | те же + `city_id` (fk `cities`, null on delete) | |
| `branch_user` | `id, user_id (fk users, cascade), branch_id (fk branches, cascade), timestamps`, `unique(user_id, branch_id)` | филиалы пользователя |

`external_id` — id той же записи в Sintegrum, ключ импорта. Строка, а не число: формат id в живом API не проверен.
Модели: `Models/DictionaryItem` (общая база) → `Branch` (+ `city()`), `City`, `Department`, `Position`.
Enum `Enums/DirectoryStatus` (`active`, `disabled`), `Enums/DictionaryType` (`branches|cities|departments|positions`:
модель, ресурс Sintegrum, порядок импорта). У `App\Models\User` связь `branches()` (через `branch_user`).

### Доступ
| Действие | Кто | Как проверяется |
|---|---|---|
| Чтение `GET /api/directory/{type}` | любой **активный** пользователь | `auth:sanctum` + `EnsureUserIsActive` |
| Создание / правка / выключение | активный `superadmin` или `admin` | Gate `manage-directory` (`Providers\DirectoryServiceProvider::MANAGE_DIRECTORY`) |
| Импорт из Sintegrum | активный `superadmin` | Gate `import-directory` (`IMPORT_DIRECTORY`): импорт использует секреты интеграций |

Гость → 401, заблокированный или без права → 403, неизвестный справочник → 404.

### Эндпоинты (`/api/directory`)
| Метод и путь | Тело / параметры | Ответ |
|---|---|---|
| `GET /{type}` | `q` (по названию, без учёта регистра), `status` (`active\|disabled`), `perPage` 1..200 (строка `"50"` тоже принимается, по умолчанию 50), `page` | `{data: [item], links, meta}`, сортировка по названию |
| `POST /{type}` | `{name, status?, city_id?}` | 201 `{data: item}`; 422 при ошибке полей |
| `PATCH /{type}/{id}` | `{name?, status?, city_id?}` | `{data: item}`; нет записи в этом справочнике → 404 |
| `POST /import` | — | `{data: {cities, branches, departments, positions, total}}`, в каждом `{created, updated, skipped}` |

`item` (`Http/Resources/DictionaryItemResource`): `id, external_id, name, status, created_at, updated_at`;
у филиала ещё `city_id` и `city: {id, name} | null`. `city_id` принимается только для `branches` (иначе 422),
`external_id` через API не задаётся. `DELETE` нет (405).

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

### Импорт из Sintegrum (`Services/SintegrumDirectoryImporter`, контракт `Contracts/DirectoryImporter`)
1. Берёт токен интеграции `sintegrum_api` через `SecretVault` и `base_url` из её настроек (по умолчанию
   `SintegrumApiDefinition::DEFAULT_BASE_URL`). Токена нет → 422 `{code: "integration_not_configured"}`, запросов нет.
2. Проверяет `base_url` через `OutboundUrlGuard` (https, порт 443, только публичные IP). Отказ → 422
   `sintegrum_<причина>` (`invalid_url`, `blocked_host`, `blocked_port`, `unresolved_host`), запросов нет.
3. `GET {base_url}/cities/list`, `/branches/list`, `/departments/list`, `/jobs/list` (должности = `jobs`), заголовок
   `Authorization: Bearer <token>`, без редиректов, таймаут запроса 15 с (соединение 5 с), **весь импорт — не дольше 60 с**.
4. Сначала скачивает все четыре списка, потом пишет в одной транзакции: если любой запрос упал, **ничего не меняется**.
5. Upsert по `external_id`: нет — создать; изменилось название/статус/город — обновить; иначе — без изменений.
   **Записи никогда не удаляются**, даже если исчезли из Sintegrum. Ручные записи (без `external_id`) не трогаются,
   но ручное переименование импортированной записи следующий импорт перезапишет значением из Sintegrum.
6. Журнал: `integration_logs` интеграции `sintegrum_api` — `directory_imported` (`by`, счётчики) или
   `directory_import_failed` (`by`, код ошибки). URL, токенов, тел ответов и текстов исключений там нет.

Ошибки Sintegrum: 401/403 → 502 `sintegrum_unauthorized`; другой не-2xx → 502 `sintegrum_http_<код>`; сеть/таймаут
соединения → 502 `sintegrum_unreachable`; неизвестный формат ответа → 502 `sintegrum_bad_response`; вышли за 60 с
→ 504 `sintegrum_timeout`.

**⚠ Не проверено на живом API (нет токена).** Пути `/{resource}/list` и заголовок `Bearer` взяты из apidoc
Sintegrum (`@api {get} /v1/<alias>/branches/list`, `AuthorizationHeaders`), где ответ — массив `{id, name, status}`
(`status` 1 = включён). Поэтому разбор ответа терпимый (`Support/SintegrumPayloadMapper`):
- список принимается как массив верхнего уровня или внутри `data` / `items`;
- запись без `id` (целое > 0 или непустая строка до 64 символов) или без `name` пропускается и считается в `skipped`;
- `status`: нет поля → активна; `1`, `"1"`, `true`, `"active"`, `"enabled"` → активна; остальное → выключена;
- `city_id` у филиала связывается с городом по `external_id` города (если такой город импортирован).
При первом реальном подключении: проверить форму ответа, наличие `city_id` у филиала, пагинацию (apidoc говорит
«без пагинации») и схему авторизации; при расхождении — поправить маппер и тесты.

### Слои
`Http/Controllers/DirectoryController` → `Http/Requests` (`ListDictionaryRequest`, `SaveDictionaryItemRequest`) →
`Services/DirectoryService` / `Contracts/DirectoryImporter` → `Contracts/DictionaryRepository`
(`Repositories/EloquentDictionaryRepository`). Бизнес-ошибки — `Exceptions/DirectoryException` (`{message, code}`).
Демо- и тестовые данные — фабрики `Database/Factories/*Factory` (синтетические названия).

### Фронтенд (`features/directory`)
`directory.page.ts` — вкладки, поиск, фильтр статуса, таблица с переименованием в строке, «Додати», кнопка импорта
с итогом по справочникам и ошибкой со ссылкой на `/admin/integrations`. `directory.store.ts` — состояние страницы
на signals (переименование и выключение — оптимистично с откатом, устаревшие ответы после смены вкладки
игнорируются). `directory.service.ts` — HTTP, `active(type)` для выпадающих списков (используется на странице
пользователей), перевод кодов ошибок в ключи i18n `directory.errors.*`. Маршрут `/admin/directory` —
`roleGuard('superadmin', 'admin')`.

## Как проверить
Тесты: `tests/Feature/Directory/DirectoryApiTest.php` (401/403/404, чтение любым активным, сортировка, фильтры,
`perPage` строкой и границы, создание/правка/выключение админом, `city_id` только у филиалов, запрет записи
рекрутеру/наблюдателю, нет DELETE), `tests/Feature/Directory/DirectoryImportTest.php` (`Http::fake`: оба формата ответа,
заголовок Bearer, не настроено → 422 без запросов, только суперадмин, повторный импорт идемпотентен и ничего не удаляет,
401 → ничего не записано и лог без токена, блокировка внутреннего адреса, плохой формат, HTTP 500, обрыв соединения
без текста исключения), `tests/Unit/Directory/*` (маппер, `BranchAccess`, `DirectoryService`).
Фронт: `directory.service.spec.ts`, `directory.store.spec.ts`.

Вручную (нужна сессия в браузере):
```bash
curl -i "https://sinhrm.vercel.app/api/directory/branches?perPage=20"   # без сессии → 401
```
