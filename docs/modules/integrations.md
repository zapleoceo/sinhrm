# Модуль Integrations

## Что это и зачем
Одна страница, где суперадмин подключает внешние сервисы: AI, Google, мессенджеры, телефонию и источники
кандидатов (сайты вакансий, Sintegrum, рекламные формы). Здесь вводятся ключи и токены.

Главное правило: **ключи хранятся только в базе и только в зашифрованном виде**. После сохранения ключ больше
никому не показывается, даже суперадмину. На странице видно только «задан / не задан», дату изменения
и последние символы (`••••1234`), чтобы понять, какой ключ стоит.

Там же **общий выключатель AI**. По умолчанию AI выключен («AI вимкнено до погодження власником»):
пока владелец не утвердил модели и промпты, система не обращается к AI-провайдерам.

## Как пользоваться
Меню слева → «Адміністрування → Інтеграції» (видно только суперадмину).
- Карточки сгруппированы: AI, Google, Месенджери, Телефонія, Джерела кандидатів. На карточке — название,
  короткое описание и статус: **Вимкнено** (off), **Демо** (demo), **Підключено** (connected), **Помилка** (error).
- **Режим** — ручное переключение «Вимкнено / Демо». Меняется сразу; если сервер отказал — возвращается как было.
  «Підключено» и «Помилка» ставит только проверка соединения.
- **Налаштувати** — раскрывает форму. Обычные поля (адрес, ID, ящик) показываются со значениями.
  Поле-ключ — это поле пароля: пустое = «не менять», новое значение = заменить, **Очистити** = удалить при сохранении.
- **Перевірити з'єднання** — есть только у тех интеграций, где проверка безопасна (см. ниже).
- Внизу раскрытой карточки — последние события (кто и что менял; только названия полей, без значений).
- **Дозволити AI** — переключатель в баннере вверху, с подтверждением. Каждое переключение пишется в журнал.

## Как устроено
### Таблицы (миграция `Database/Migrations/2026_09_26_100001_create_integrations_tables.php`)
| Таблица | Колонки | Заметки |
|---|---|---|
| `integrations` | `id, key (unique), status (off\|demo\|connected\|error), settings jsonb, last_checked_at, last_error, timestamps` | строка создаётся при первом изменении; нет строки = `off`. `settings` — только несекретные поля. `last_error` — код ошибки, секретов не содержит |
| `integration_secrets` | `id, integration_id (fk, cascade), name, value text, updated_by (fk users, null on delete), timestamps`, `unique(integration_id, name)` | `value` — шифротекст |
| `integration_logs` | `id, integration_id (fk, cascade), level (info\|warning\|error), message, context jsonb, created_at` | аудит: id пользователя, имена полей, статусы. Значений нет |

Глобальный флаг AI — строка `integrations` с `key = 'ai_policy'` и `settings = {"enabled": false}`.

### Шифрование и хранилище секретов
- `Models/IntegrationSecret` — каст `encrypted` (Laravel `Crypt`, AES-256 с `APP_KEY`), поле `value` скрыто
  от сериализации (`$hidden`).
- `Contracts/SecretVault` (`get`, `put`, `forget`, `describe`) — **единственное место**, где читаются
  расшифрованные значения. Реализация `Repositories/EloquentSecretVault`. Другие модули берут токены
  только через этот интерфейс (DI), не из таблицы.
- `describe()` отдаёт `SecretMeta {is_set, updated_at, masked}`. Маска (`DTO/SecretMeta::mask`) — `••••` +
  не больше 4 последних символов и не больше четверти длины (у короткого ключа видно меньше или ничего).

### Реестр интеграций (Open/Closed)
- `Contracts/IntegrationDefinition`: `key()`, `group()`, `fields(): list<FieldSpec>`, `supportsCheck()`.
- `Contracts/ConnectionChecker`: `check(IntegrationConfig): CheckResult`. Определение, которое умеет проверку,
  реализует оба интерфейса; `AbstractDefinition::supportsCheck()` = «реализует ли `ConnectionChecker`».
- `DTO/FieldSpec {name, type: text|secret|url|select, required, options, default}`.
- Определения регистрируются тегом `integrations.definitions` (`IntegrationsServiceProvider::DEFINITIONS_TAG`),
  `Support/IntegrationRegistry` собирает их, дубли ключей — ошибка.

**Как добавить интеграцию (2 шага):**
1. Класс в `Definitions/`, например `final class FooDefinition extends AbstractDefinition` с `key()`, `group()`,
   `fields()` (и `implements ConnectionChecker` + `check()`, если проверка безопасна).
2. Одна строка `FooDefinition::class` в списке `tag([...])` в `Providers/IntegrationsServiceProvider::register()`.

Фронтенд подхватит карточку сам; подписи — ключи `integrations.items.foo.{name,description}` и
`integrations.fields.<поле>` в `public/i18n/{uk,ru,en}.json`.

### Какие проверки ходят в сеть и почему
| Интеграция | Проверка | Сеть |
|---|---|---|
| `ai_broker` | `GET {base_url}/v1/health` (публичный, **без ключа**), таймаут 10 с | да. Эндпоинты chat/jobs не вызываются: AI-вызовы запрещены до решения владельца |
| `telegram_business` | `GET https://api.telegram.org/bot<token>/getMe` (только чтение), таймаут 10 с | да. URL содержит токен, поэтому ни URL, ни текст исключения не пишутся в лог и в ответ: только коды `unauthorized`, `http_<код>`, `connection_failed` |
| `sintegrum_api` | проверка формата URL (https) и наличия токена → статус `demo`, `last_error = not_verified` | **нет**. TODO: схема авторизации Sintegrum API не подтверждена |
| остальные (`openrouter`, `deepgram`, `google_*`, `whatsapp_cloud`, `viber`, `wazzup`, `phonet`, `ringostat`, `binotel`, `work_ua`, `robota_ua`, `djinni`, `meta_lead_ads`) | нет (`supports_check: false`) | нет. OpenRouter и Deepgram — AI/платные вызовы; Google — появится OAuth-подключение |

Защита в глубину: перед записью `last_error` и лога `IntegrationService` заменяет любые значения секретов в тексте
на `***` и обрезает до 255 символов. Если обязательный ключ не задан, проверка не выполняется
(`missing_secret:<имя>`).

### Доступ
Gate `manage-integrations` (`Providers\IntegrationsServiceProvider::MANAGE_INTEGRATIONS`): активный суперадмин.
Все маршруты: `auth:sanctum` + `EnsureUserIsActive` + `can:manage-integrations`. Гость → 401, другая роль или
заблокированный → 403. `{integration}` превращается в определение из реестра, неизвестный ключ → 404.

### Эндпоинты (`/api/integrations`)
| Метод и путь | Тело | Ответ |
|---|---|---|
| `GET /` | — | `{data: [integration], ai_policy: {enabled}}` |
| `PUT /{key}` | `{settings?: {name: string}, secrets?: {name: string\|null}}` | `{data: integration}`; 422 при ошибке полей |
| `POST /{key}/check` | — | `{data: integration}` со статусом `connected\|error\|demo`; нет проверки → 422 `{code: "check_not_supported"}` |
| `POST /{key}/status` | `{status: off\|demo}` | `{data: integration}` |
| `GET /{key}/logs` | — | `{data: [{id, level, message, context, created_at}]}`, последние 50 |
| `PUT /ai-policy` | `{enabled: bool}` | `{data: {enabled}}`, пишется в журнал `ai_policy` (уровень warning) |

`integration` (`Http/Resources/IntegrationResource`): `key, group, status, supports_check, last_checked_at,
last_error, updated_at, fields[]`. Поле: `name, type, required, options, default` + `value` (несекретное) или
`secret: {is_set, updated_at, masked}` (секретное). Значения секретов в ответах отсутствуют всегда.

Правила `PUT` (`Http/Requests/UpdateIntegrationRequest`, генерируются из `FieldSpec`):
- `settings`: разрешены только несекретные поля интеграции; `url` — http(s) URL; `select` — одно из `options`;
  обязательное поле нельзя очистить, если передан объект `settings`.
- `secrets`: разрешены только секретные поля; `"значение"` — записать, `null` — удалить, `""` или отсутствие ключа —
  не менять. Глобальный middleware превращает `""` в `null`, поэтому запрос берёт `secrets` из исходного JSON.
- Обязательность секретов проверяется при проверке соединения, а не при сохранении (можно сохранить частично).
- Изменение ключа не сбрасывает статус: после смены ключа нажмите «Перевірити з'єднання».

### Слои
`Http/Controllers/IntegrationsController` → `Http/Requests/*` → `Services/IntegrationService`,
`Services/AiPolicyService` → `Contracts/IntegrationRepository` (`Repositories/EloquentIntegrationRepository`),
`Contracts/SecretVault` (`Repositories/EloquentSecretVault`). `Contracts/AiPolicy::enabled()` — для других модулей:
любой код, который вызывает AI, обязан сначала спросить его.

### Фронтенд (`features/integrations`)
`integrations.page.ts` — баннер AI (переключатель + диалог `confirm-ai.dialog.ts`), группы карточек, состояния
«загрузка / пусто / ошибка с повтором». `integrations.store.ts` — состояние страницы на signals; режим и AI
меняются оптимистично с откатом. `integration-card.ts` — форма из `FieldSpec` (секреты — `type=password`, в
placeholder маска или «не задано», кнопка «Очистити»), «Зберегти», «Перевірити з'єднання», последние события.
`integrations.service.ts` — HTTP, `buildUpdate()` (тело PUT из формы), перевод кодов ошибок в ключи i18n.
Маршрут `/admin/integrations` — `roleGuard('superadmin')`.

## Как проверить
Тесты: `tests/Feature/Integrations/IntegrationsApiTest.php` (401/403, 404 неизвестного ключа, список и маскирование
со сканированием всего ответа, шифрование в БД, семантика set/unchanged/delete, валидация, проверки через
`Http::fake` — Telegram ok/401/обрыв, AI Broker health ok/503, Sintegrum без сети, логи без секретов, лимит 50,
AI-флаг), `tests/Unit/Integrations/*` (хранилище: шифротекст ≠ открытый текст, маска; реестр; сервис: очистка
секретов из сообщений, пропуск проверки без ключа). Фронт: `integrations.service.spec.ts`, `integrations.store.spec.ts`.

Вручную (нужна сессия суперадмина):
```bash
curl -i "https://sinhrm.vercel.app/api/integrations"   # без сессии → 401
```
