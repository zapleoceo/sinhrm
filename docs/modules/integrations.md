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
  «Підключено» и «Помилка» ставит только проверка соединения; после изменения настроек или ключа такая карточка возвращается в «Демо» до новой проверки.
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
| `telegram_business` | `GET https://api.telegram.org/bot<token>/getMe` (только чтение), таймаут 10 с | да. URL содержит токен. До запроса токен проверяется по формату `^\d+:[A-Za-z0-9_-]+$` (иначе `invalid_token`, без запроса), вокруг вызова ловится **любой** `Throwable`: в ответ и лог попадают только коды `unauthorized`, `http_<код>`, `connection_failed` |
| `sintegrum_api` | проверка URL через `OutboundUrlGuard` и наличия токена → статус `demo`, `last_error = not_verified` | только DNS-резолв хоста, HTTP-запроса **нет**. TODO: схема авторизации Sintegrum API не подтверждена. Реальные запросы к Sintegrum делает импорт справочников (ниже) |
| остальные (`openrouter`, `deepgram`, `google_*`, `whatsapp_cloud`, `viber`, `wazzup`, `phonet`, `ringostat`, `binotel`, `work_ua`, `robota_ua`, `djinni`, `meta_lead_ads`) | нет (`supports_check: false`) | нет. OpenRouter и Deepgram — AI/платные вызовы; Google подключается OAuth-согласием (модуль GoogleWorkspace), у его карточек нет полей |

Защита в глубину: перед записью `last_error` и лога `IntegrationService` заменяет любые значения секретов в тексте
на `***` и обрезает до 255 символов. Если обязательный ключ не задан, проверка не выполняется
(`missing_secret:<имя>`).

### Защита от SSRF (`Support/OutboundUrlGuard`)
Каждый checker перед любым исходящим запросом прогоняет URL через `OutboundUrlGuard::check()`:
- только `https`, без логина/пароля в URL и без пробелов (иначе `invalid_url`); поля типа `url` и в API принимают только https;
- порт только 443, другие — лишь если checker явно их разрешил (иначе `blocked_port`);
- хост резолвится (`Contracts/HostResolver`, реализация `Support/DnsHostResolver`: `gethostbynamel` + AAAA),
  **каждый** адрес должен быть публичным: запрещены loopback (127/8, `::1`), RFC1918 (10/8, 172.16/12, 192.168/16),
  link-local 169.254/16 (включая метаданные облака 169.254.169.254), `fc00::/7`, `fe80::/10`, IPv4-mapped IPv6,
  0/8 и прочие зарезервированные диапазоны (иначе `blocked_host`); не резолвится — `unresolved_host`;
- HTTP-клиент чекеров работает с `allow_redirects: false`, чтобы публичный хост не перенаправил запрос внутрь.
Ограничение: DNS-rebinding между проверкой и запросом не исключён (резолв делается до запроса).
В тестах `HostResolver` подменяется `tests/Support/FakeHostResolver`.

### Кто использует интеграции
| Интеграция | Потребитель | Что берёт |
|---|---|---|
| `google_gmail`, `google_calendar`, `google_sheets` | модуль GoogleWorkspace ([google-workspace.md](google-workspace.md)): OAuth-подключение пишет `refresh_token`/`access_token` в `SecretVault`, статус `connected` и несекретные `settings` (`account_email, scopes, connected_by, connected_at, access_expires_at`); отзыв доступа → `error` + `last_error = reconnect_required`. Потребители: почтовый агент ([mail-agent.md](mail-agent.md)), встречи из карточки, импорт из Google Sheets | токены только через `GoogleTokenProvider` (кэш + refresh); журнал `google_connected`, `reconnect_required`, `sheets_imported` (без значений). Полей у карточек нет: подключение — кнопка «Підключити Google» над группой Google (`features/google-workspace/google-connect.panel.ts`); ручной статус `off`/`demo` выключает использование до нового подключения |
| `sintegrum_api` | импорт справочников — `Directory\Services\SintegrumDirectoryImporter` ([directory.md](directory.md)) | `base_url` (настройка), `token` (через `SecretVault`); запросы `GET {base_url}/{cities,branches,departments,jobs}/list` с `Authorization: Bearer`, через `OutboundUrlGuard`; журнал `directory_imported` / `directory_import_failed` в `integration_logs` (только счётчики/код ошибки) |

### Вычистка секретов из логов (`Support/SecretScrubber`)
В `bootstrap/app.php` зарегистрирован репортер исключений: если текст исключения (или любого `previous`)
содержит Telegram-токен (`bot\d+:[A-Za-z0-9_-]+`), значение `Bearer …` или любой секрет, расшифрованный или
записанный хранилищем в этом запросе (`EloquentSecretVault` сообщает их скрабберу), в лог пишется одна строка с
заменой на `[redacted]` без стека, а стандартный отчёт отменяется. Исключения без секретов логируются как обычно.

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
- `settings`: разрешены только несекретные поля интеграции; `url` — только https URL; `select` — одно из `options`;
  обязательное поле нельзя очистить, если передан объект `settings`.
- `secrets`: разрешены только секретные поля; `"значение"` — записать, `null` — удалить, `""` или отсутствие ключа —
  не менять. Глобальный middleware превращает `""` в `null`, поэтому запрос берёт `secrets` из исходного JSON.
- Обязательность секретов проверяется при проверке соединения, а не при сохранении (можно сохранить частично).
- Любое реальное изменение настроек или ключей интеграции в статусе `connected` или `error` возвращает её в `demo`,
  очищает `last_error` и `last_checked_at` и пишет в журнал `recheck_required` («настройки изменены, нужна повторная
  проверка»). Статус `off` и `demo` не меняется; сохранение без изменений статус не трогает.

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
В группе Google над карточками — панель подключения Google (`features/google-workspace/google-connect.panel.ts`,
[google-workspace.md](google-workspace.md)).
Маршрут `/admin/integrations` — `roleGuard('superadmin')`.

## Как проверить
Тесты: `tests/Feature/Integrations/IntegrationsApiTest.php` (401/403, 404 неизвестного ключа, список и маскирование
со сканированием всего ответа, шифрование в БД, семантика set/unchanged/delete, валидация, проверки через
`Http::fake` — Telegram ok/401/обрыв, AI Broker health ok/503, Sintegrum без сети, логи без секретов, лимит 50,
AI-флаг, битый токен без запроса и без записи в лог, любой Throwable → код, https-only, SSRF-блокировки, сброс статуса после изменения), `tests/Unit/Integrations/*` (в т.ч. `OutboundUrlGuardTest` — все запрещённые диапазоны, `SecretScrubberTest` — редактирование через обработчик исключений и `Log::spy`) (хранилище: шифротекст ≠ открытый текст, маска; реестр; сервис: очистка
секретов из сообщений, пропуск проверки без ключа). Фронт: `integrations.service.spec.ts`, `integrations.store.spec.ts`.

Вручную (нужна сессия суперадмина):
```bash
curl -i "https://sinhrm.vercel.app/api/integrations"   # без сессии → 401
```
