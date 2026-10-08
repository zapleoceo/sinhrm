# Модуль Integrations

## Что это и зачем
Одна страница, где суперадмин подключает внешние сервисы: AI, Google, мессенджеры, телефонию и источники
кандидатов (рекламные формы). Здесь вводятся ключи и токены.

> **Сайты вакансий (Work.ua, Robota.ua, Djinni) — не интеграции.** У них нет открытого API для работодателя, поэтому
> карточек с токеном здесь нет (были заготовками и удалены 2026-10-09 вместе с их строками в базе). Кандидаты с этих
> сайтов попадают в систему двумя путями: **письма-отклики** разбирает почтовый агент ([mail-agent.md](mail-agent.md)),
> **профиль с открытой страницы** добавляет браузерное расширение ([extension.md](extension.md)). Пробел: расширение
> пока **не умеет Robota.ua** (только LinkedIn, Work.ua, Djinni, DOU) — с Robota.ua кандидаты приходят только через почту.

Главное правило: **ключи хранятся только в базе и только в зашифрованном виде**. После сохранения ключ больше
никому не показывается, даже суперадмину. На странице видно только «задан / не задан», дату изменения
и последние символы (`••••1234`), чтобы понять, какой ключ стоит.

Там же **общий выключатель AI**. По умолчанию AI выключен. Владелец утвердил AI (AI Broker, возможность `chat:fast`):
после включения переключателя и ввода ключа проекта AI Broker система оценивает звонки по скриптам, сортирует почту
незнакомых отправителей и делает ШІ-скринінг кандидатов — подробно в [ai.md](ai.md). Над карточками группы AI —
панель «ШІ: стан, використання і перевірка» (использование за сегодня против лимитов и «Тестовий запит»).

## Как пользоваться
- Задание и удаление ключа пишутся в журнал действий ([audit.md](audit.md)) только как факт: имя ключа, кто и когда — без значения. Поэтому `EloquentSecretVault::forget` удаляет ключ по модели, а не массовым запросом.
Меню слева → «Адміністрування → Інтеграції» (видно только суперадмину).
- Карточки сгруппированы: AI, Google, Месенджери, Телефонія, Джерела кандидатів, Документи (заготовка КЕП-підпису). На карточке — название,
  короткое описание и статус: **Вимкнено** (off), **Демо** (demo), **Підключено** (connected), **Помилка** (error).
- **Режим** — ручное переключение «Вимкнено / Демо». Меняется сразу; если сервер отказал — возвращается как было.
  «Підключено» и «Помилка» ставит только проверка соединения; после изменения настроек или ключа такая карточка возвращается в «Демо» до новой проверки.
- **Налаштувати** — раскрывает форму. Обычные поля (адрес, ID, ящик) показываются со значениями.
  Поле-ключ — это поле пароля: пустое = «не менять», новое значение = заменить, **Очистити** = удалить при сохранении.
- **Перевірити з'єднання** — есть только у тех интеграций, где проверка безопасна (см. ниже).
- Внизу раскрытой карточки — последние события (кто и что менял; только названия полей, без значений).
- **Дозволити AI** — переключатель в баннере вверху, с подтверждением. Каждое переключение пишется в журнал.

## Как устроено

**Ошибки бизнес-правил** (DRY, 2026-10-08): `Exceptions/IntegrationException` наследует `Core\Exceptions\BusinessRuleException` — общий конструктор (код, HTTP-статус, `extra`) и `render()` в JSON `{message, code, ...extra}`; модуль объявляет только именованные коды, ответ API прежний.

### Таблицы (миграция `Database/Migrations/2026_09_26_100001_create_integrations_tables.php`)
| Таблица | Колонки | Заметки |
|---|---|---|
| `integrations` | `id, key (unique), status (off\|demo\|connected\|error), settings json, last_checked_at, last_error, timestamps` | строка создаётся при первом изменении; нет строки = `off`. `settings` — только несекретные поля. `last_error` — код ошибки, секретов не содержит |
| `integration_secrets` | `id, integration_id (fk, cascade), name, value text, updated_by (fk users, null on delete), timestamps`, `unique(integration_id, name)` | `value` — шифротекст |
| `integration_logs` | `id, integration_id (fk, cascade), level (info\|warning\|error), message, context json, created_at` | аудит: id пользователя, имена полей, статусы. Значений нет |

Глобальный флаг AI — строка `integrations` с `key = 'ai_policy'` и `settings = {"enabled": false}`.

Поля `ai_broker` (все — настройки AI, читает `Ai/Support/AiSettingsReader`): `base_url`, секрет `project_key`,
селекты `capability` и `capability_{script_evaluation,mail_classification,candidate_screening,assistant_chat}`
(`chat:fast|chat:smart|chat:sales|structured`, по умолчанию `chat:fast` — `AiBrokerDefinition::DEFAULT_CAPABILITY`, у
помощника — `ASSISTANT_CAPABILITY`, тоже `chat:fast`), `model` (без значения по умолчанию: пусто =
модель выбирает брокер), `max_requests_per_day` (200), `daily_cap_usd` (2), выключатели `ai_script_evaluation`,
`ai_mail_classification`, `ai_candidate_screening`, `ai_assistant_chat` (`on`), `ai_screening_auto` и `native_tools`
(`off`). Подписи вариантов селектов —
`integrations.options.<значение>`.

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
| `ai_broker` | `GET {base_url}/v1/health` (публичный, **без ключа**), таймаут 10 с | да. Сами AI-запросы (`/v1/jobs`) шлёт модуль Ai ([ai.md](ai.md)) и только при включённом выключателе |
| `telegram_business` | `GET https://api.telegram.org/bot<token>/getMe` (только чтение), таймаут 10 с | да. URL содержит токен. До запроса токен проверяется по формату `^\d+:[A-Za-z0-9_-]+$` (иначе `invalid_token`, без запроса), вокруг вызова ловится **любой** `Throwable`: в ответ и лог попадают только коды `unauthorized`, `http_<код>`, `connection_failed` |
| `whatsapp_cloud` | `GET https://graph.facebook.com/v21.0/{phone_number_id}?fields=id` (только чтение), токен в заголовке `Authorization`, таймаут 10 с; `phone_number_id` должен быть числом (иначе `invalid_url` без запроса) | да. 401 или ошибка Graph 190 → `unauthorized` |
| `viber` | `POST https://chatapi.viber.com/pa/get_account_info` (только чтение), токен в заголовке `X-Viber-Auth-Token` | да. Viber `status: 2` → `unauthorized` |
| остальные (`openrouter`, `deepgram`, `google_*`, `wazzup`, `phonet`, `ringostat`, `binotel`, `meta_lead_ads`, `kep_signing`) | нет (`supports_check: false`) | нет. OpenRouter и Deepgram — AI/платные вызовы; Google подключается OAuth-согласием (модуль GoogleWorkspace), у его карточек нет полей |

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
| `telegram_business`, `whatsapp_cloud`, `viber`, `phonet`, `ringostat`, `binotel` | модуль Channels ([channels.md](channels.md)): вебхуки → лента кандидата, отправка из карточки, регистрация вебхука, тест, демо-события | конфиг через `Services/IntegrationConfigLoader` (настройки + секреты из `SecretVault`, только в памяти). Секреты вебхуков: `telegram_business.webhook_secret` (создаёт «Зареєструвати вебхук»), `whatsapp_cloud.app_secret` + `verify_token`, `viber.token`, у телефонии `webhook_token` (`?token=`, временная схема) и у Ringostat `callback_extension`. Журнал: `webhook_received/rejected/registered/register_failed`, `test_sent/failed`, `send_failed`, `simulated`, `delivery_failed`, `business_connected/disconnected`, `call_requested/failed` — только коды и счётчики. Режим канала = статус интеграции (`off` → вебхуки 404; `demo` → принимаются, отправка без провайдера; `connected`/`error` → реально) |
| `kep_signing` (группа `documents`) | **заготовка, не используется**: квалифицированная подпись документов (Дія.Підпис / Вчасно) для модуля Documents ([documents.md](documents.md)) | поля `provider` (`diia_signature` \| `vchasno`) и секрет `api_token` (необязательный); проверки нет, статус остаётся `off`. Сейчас документы подтверждаются только «Ознайомлений» (`manual_ack`); метод `kep_pending` зарезервирован. До реализации нужны: выбор провайдера, доступ к API, юридическая проверка процесса подписи |
| `workflows` (служебная строка, без карточки) | модуль Workflows ([workflows.md](workflows.md)) хранит в `SecretVault` ключи подписи вебхуков по шаблонам (`webhook_secret:<id шаблона>`) | вебхук-шаг читает ключ при отправке; API шаблона показывает только `is_set` и маску |

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
| `GET /itstep-directory/status` | — | `{data: {status, missing_inputs, scope_configured, writes_enabled: false}}`; доступен только суперадмину |
| `GET /itstep-directory/preview` | — | read-only preview complete normalized snapshot from the injected gateway; `409 dependency_pending` пока SDK/source contract не готовы, `422 snapshot_invalid` для unknown/incomplete payload |
| `GET /itstep-directory/synthetic-preview` | — | `{synthetic: true, data: preview}` на фиксированных демонстрационных данных; не вызывает source gateway |

### Каталог сотрудников Itstep — подготовка

`EmployeeDirectoryGateway` зарегистрирован на `PendingEmployeeDirectoryGateway`: состояние `dependency_pending`, fetch завершается типизированной ошибкой и не выполняет сетевых запросов. Preview разрешается только если gateway явно сообщает настроенный namespace, а полный snapshot содержит ровно тот же namespace. Источник блокирует получение данных до установки `itstep/user-client` и подтверждения схемы ответа, namespace/company scope, доверенного endpoint/auth и правил stable employee ID. SDK path `/api/v1/profiles` сам по себе не подтверждает response contract.

`EmployeeDirectoryPreview` принимает только полный normalized snapshot с точным набором известных полей; unknown shape, пустой source ID и incomplete pages отклоняются. Идентичные повторы сворачиваются детерминированно, разные строки с одним ID показываются как конфликт. Результат — только ручной план identity review; branch/position/status явно остаются `unconfirmed`. Ни Employee, ни User, ни роли/статусы не меняются. Synthetic preview использует выдуманные demo-значения и отдельно помечен в API/UI; он не доказывает доступность или схему Itstep. В панели состояние источника и synthetic preview загружаются независимо: ошибка одного запроса остаётся видна независимо от порядка завершения второго, а при повторной загрузке старый preview сразу очищается.

После получения недостающих входных данных нужно добавить адаптер поверх установленного официального SDK и подтвердить mapping до подключения данных. Не включать автоназначение ролей, создание пользователей или деактивацию сотрудников на этом этапе.

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
`Contracts/SecretVault` (`Repositories/EloquentSecretVault`). `Services/IntegrationConfigLoader` — единая сборка
`IntegrationConfig` (настройки с умолчаниями + расшифрованные секреты) и статуса для проверок и других модулей.
`Contracts/AiPolicy::enabled()` — для других модулей: любой код, который вызывает AI, обязан сначала спросить его;
на практике AI вызывается только через `Ai/Services/AiService`, который проверяет флаг сам.

### Фронтенд (`features/integrations`)
`integrations.page.ts` — баннер AI (переключатель + диалог `confirm-ai.dialog.ts`), группы карточек, состояния
«загрузка / пусто / ошибка с повтором». `integrations.store.ts` — состояние страницы на signals; режим и AI
меняются оптимистично с откатом. `integration-card.ts` — форма из `FieldSpec` (секреты — `type=password`, в
placeholder маска или «не задано», кнопка «Очистити»; варианты селектов — `integrations.options.*`), «Зберегти»,
«Перевірити з'єднання», последние события. В группе AI над карточками — `features/ai/ai-panel.ts` ([ai.md](ai.md)).
`integrations.service.ts` — HTTP, `buildUpdate()` (тело PUT из формы), перевод кодов ошибок в ключи i18n.
У карточек групп «Месенджери» и «Телефонія» в раскрытом виде — блок «Вебхук» (`features/channels/channel-panel.ts`:
адрес с копированием, регистрация, тест, демо-событие — [channels.md](channels.md)).
В группе Google над карточками — панель подключения Google (`features/google-workspace/google-connect.panel.ts`,
[google-workspace.md](google-workspace.md)).
Маршрут `/admin/integrations` — `roleGuard('superadmin')`.

**Интерфейс (2026-09-26):** У названия каждой интеграции — иконка сервиса (`app-channel-icon`, Font Awesome Free: Telegram, WhatsApp, Viber, Meta, Google, телефония, AI…; [core.md](core.md)).

**Вид (рестайл C «Маршрут», 2026-10-02).** Статус интеграции — пилюля `.app-pill` (`INTEGRATION_STATUS_TONE`: подключена ● good, ошибка ■ bad, демо ○ info, выключена — пунктирный ○) вместо чипа; карточки — белая карточка с линией 1.5px; баннер «ИИ включён/выключен» — линия и «рельса» 4px в цвете состояния плюс иконка того же цвета. Тест вида — `features/integrations/integrations.restyle.spec.ts` (контракт стилей: только токены темы, без hex, линии 1.5px, без «бледности» через opacity).

### Общие хелперы Core (2026-10-02)
- gate `manage-integrations` задаётся `ModuleServiceProvider::defineRoleGate(…, [UserRole::Superadmin])`: только активный superadmin;
- текущий пользователь в контроллерах — общий трейт `Core\Http\Concerns\ResolvesActor` вместо приватной копии `actor()`.

Поведение API не менялось; подробности — [core.md](core.md), раздел «Общие хелперы модулей».

### Общие примитивы фронта
Общий код фронта лежит в `frontend/src/app/core` ([core.md](core.md)); фича его только вызывает.
- Ошибки API → i18n-ключ: `integrationErrorKey` — обёртка над общим `apiErrorKey` (`core/api/api-error.ts`) со своими кодами, списком статусов и запасным ключом; набор ключей и тексты прежние.
- Короткие уведомления (toast) — `NotifyService.show(key, { params?, duration? })` из `core/ui/notify.service.ts` вместо своего `toast()` с `MatSnackBar`; тексты, длительности и доступность (вежливая live-область snack bar) прежние.
- HTTP-сервис фичи снимает обёртку ответа `{ data }` общим оператором `unwrapData()` (`core/api/unwrap-data.ts`, тип `DataEnvelope<T>` из `core/api/api.model.ts`) вместо своего `map((r) => r.data)`; параметры запроса без пустых значений — `toParams` из `core/api/http-params.ts`, страница списка — `Paged<T>` оттуда же. Контракт API не менялся.

## Как проверить
Даты последней проверки и событий журнала используют активный язык интерфейса: `uk-UA`, `ru-RU`, `en-GB` (существующая карта `DATE_LOCALES`). Переключение языка обновляет уже показанные даты; формат `short` берётся из locale data Angular, часовой пояс остаётся локальным браузерным. Значения ISO/API, часовой пояс пользователя и настройки профиля не изменяются. Null, пустые и некорректные даты показываются пустыми без ошибки карточки; дата последней проверки в таком случае скрыта. Проверки: `integration-date.spec.ts` и `integration-dates.pw.ts` (uk/ru/en × UTC/Kyiv/Los Angeles, зимний/летний offset и переход через полночь); тесты CI создают 12 viewport PNG.

Тесты: `tests/Feature/Integrations/IntegrationsApiTest.php` (401/403, 404 неизвестного ключа, список и маскирование
со сканированием всего ответа, шифрование в БД, семантика set/unchanged/delete, валидация, проверки через
`Http::fake` — Telegram ok/401/обрыв, AI Broker health ok/503, логи без секретов, лимит 50,
AI-флаг, битый токен без запроса и без записи в лог, любой Throwable → код, https-only, SSRF-блокировки, сброс статуса после изменения), `tests/Feature/Integrations/EmployeeDirectoryApiTest.php` (superadmin access, pending/no-network, complete and incomplete snapshots, synthetic endpoint, no employee/user writes), `tests/Unit/Integrations/EmployeeDirectoryPreviewTest.php` (shape/scope, ID validation, duplicate conflicts, deterministic plan), `tests/Unit/Integrations/*` (в т.ч. `OutboundUrlGuardTest` — все запрещённые диапазоны, `SecretScrubberTest` — редактирование через обработчик исключений и `Log::spy`) (хранилище: шифротекст ≠ открытый текст, маска; реестр; сервис: очистка
секретов из сообщений, пропуск проверки без ключа). Фронт: `integrations.service.spec.ts`, `integrations.store.spec.ts`.

Вручную (нужна сессия суперадмина):
```bash
curl -i "https://sinhrm.vercel.app/api/integrations"   # без сессии → 401
```

## Доступ к модулю

Ключ модуля `integrations`. Это **базовый** модуль: его нельзя выключить или ограничить по ролям на странице «Адміністрування → Модулі». Подробнее — [modules-access.md](modules-access.md).

- На узком экране (< 768px) страница не прокручивается вбок: широкие элементы (таблицы, переключатели, длинные строки) прокручиваются или переносятся внутри своего блока.

## AI Broker: тексты вакансий (2026-10-25)
В настройках `ai_broker` два новых поля: `capability_vacancy_text` (по умолчанию `chat:fast`) и выключатель
`ai_vacancy_text` (по умолчанию `on`) для кнопки «Створити з ШІ» в форме вакансии ([ai.md](ai.md)).

### Переподключение Google
В карточке Gmail, Календаря или Таблиц есть кнопка подключения через Google. Она открывает существующий общий OAuth-поток для Gmail, Calendar и Sheets; рядом перечислены права на чтение/отправку почты, события календаря и чтение таблиц. Причина истёкшего/отозванного доступа и события журнала переведены. Ссылка с главной раскрывает, фокусирует и прокручивает нужную карточку (`?integration=google_gmail`). Токены вручную не вводятся. Проверка: component-тест integration-card.spec.ts (действие, deep link, ошибка журнала), CI и реальные screenshots preview.

Кнопки в карточках активны только после подтверждения настройки OAuth в общем Google-блоке; при отсутствии настройки или ошибке получения статуса переход недоступен.

Доступность действия различает проверку настройки, готовность, отсутствие настройки и ошибку проверки. Рядом с кнопкой каждого Google-сервиса показаны причина недоступности и следующий шаг: дождаться проверки, обратиться к администратору системы или обновить страницу. Причина истёкшего доступа и записи журнала не предлагают нажимать недоступную кнопку. Верхняя кнопка и карточки используют одинаковое native disabled-состояние Angular Material; на desktop зона описания Google-карточки сохраняет место для двух строк без обрезки текста.

Проверки состояний: `google-connect.panel.spec.ts` и `integration-card.spec.ts` покрывают loading/unconfigured/error/ready и восстановление ссылки. Синтетические Playwright-сценарии `integrations-states.pw.ts` используют `fixtures/scenarios/google-integrations.json`: подключённый Google, истёкший доступ с журналом, ошибка загрузки журнала. Они проверяют доступность действий, переводы, выравнивание, layout/axe и отсутствие OAuth/внешних запросов; screenshots `integrations-google-{connected,reconnect,logs-error}.png` обязательны во всех четырёх viewport/theme проектах CI. Новые screenshots требуют независимого просмотра после CI.

UI parity инвентарь desktop/mobile осознанно дополнен тремя disabled-ссылками подключения Google для синтетического сценария с ненастроенным OAuth. Реальные screenshots CI просмотрены: новые элементы ожидаемы, прежние контролы не удалены; окончательная проверка нового состояния выполняется повторным CI.

Connected/error remain automatic observations: the mode control shows the current translated state as a disabled option, while only off/demo can be assigned manually. Mobile deep-link cards reserve space above their header for the sticky navigation bar; configured-state CI checks both geometry and displayed mode.
MySQL 8.4 only (ADR 0011, 2026-10-08): `integrations.settings` is `json NOT NULL` without a database default (MySQL JSON has no literal default; the driver-specific `'{}'` default branch is gone); the Integration model supplies an empty object for lazily created rows. Test: `tests/Feature/Integrations/IntegrationSettingsMysqlSchemaTest` (column type/default from `information_schema`, a row created without settings reads back `[]`).
