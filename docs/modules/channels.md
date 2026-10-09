# Модуль Channels (мессенджеры и телефония)

## Что это и зачем
Кандидаты пишут рекрутеру в Telegram, WhatsApp, Viber и звонят. Модуль Channels приносит эти сообщения и звонки **в карточку
кандидата сами** — рекрутеру не нужно переписывать переписку руками. Если по телефону, e-mail или @username понятно, чей это
кандидат, касание попадает в его ленту; если нет — во «Вхідні», где его привязывают одним кликом. Дальше вся переписка
этого чата сама идёт к тому же кандидату.

Из карточки можно **отправить** сообщение в Telegram / WhatsApp / Viber, а также **письмо на e-mail** кандидата
(через подключённый Gmail — см. [google-workspace.md](google-workspace.md#отправка-писем-contractsmailer--servicesgmailmailer)): оно уходит через подключённый канал и сразу
появляется в ленте. Если канал не подключён — система так и скажет и предложит «Записати вручну».

Пока токенов нет, канал можно перевести в режим **«Демо»**: кнопка «Демо-подія» создаёт правдоподобное входящее сообщение
или звонок тем же путём, что и настоящий вебхук, — так продукт можно показать до подключения. Отправка из карточки в демо
записывает сообщение в ленту без обращения к провайдеру (помечено «демо»).

## Как пользоваться
### Рекрутер (карточка кандидата)
- В поле касания выберите канал Telegram / WhatsApp / Viber. Если канал подключён, рядом с «Записати» появится
  **«Надіслати»** («Надіслати (демо)» в демо-режиме). Текст можно вставить из шаблона скрипта («Шаблон»).
- Ошибки простыми словами: «Кандидат ще не писав у цей канал» (Telegram/Viber — ответить можно только в существующий
  чат), «WhatsApp: минуло понад 24 години… потрібен шаблон» (правило WhatsApp), «Канал не підключено» → кнопка
  «Записати вручну» сохраняет касание как обычно.

### Суперадмин (Адміністрування → Інтеграції)
У карточек групп «Месенджери» и «Телефонія» в раскрытом виде есть блок **«Вебхук»**: адрес для консоли провайдера с кнопкой
копирования, как проверяется подлинность, для телефонии — готовая строка заголовка `X-Webhook-Token: ВАШ_ТОКЕН_ВЕБХУКА` с
кнопкой копирования (значение секрета не показывается никогда) и предупреждение «Застаріле…», пока включён переходный
`?token=` (см. «Аутентификация вебхуков телефонии»), короткая инструкция, кнопки **«Зареєструвати вебхук»** (Telegram, Viber),
**«Надіслати тест»** (на chat id / телефон / Viber id, который вы вводите) и **«Демо-подія»** (только в режиме «Демо»).
Ниже — «Останні події»: принятые вебхуки, отклонённые подписи, регистрация, тесты, ошибки отправки (только коды, без текстов).

### Шаги владельца по провайдерам
| Провайдер | Что ввести в «Інтеграції» | Куда вставить адрес | Статус формата |
|---|---|---|---|
| Telegram Business | `bot_token` (от @BotFather) → Зберегти → режим «Демо» → «Зареєструвати вебхук» (система сама вызовет `setWebhook` и создаст `webhook_secret`) | никуда: регистрирует кнопка. Затем в Telegram рекрутера: Настройки → Telegram Business → Чат-боты → добавить бота **с правом отвечать** | по документации Bot API |
| WhatsApp Cloud | `phone_number_id`, `waba_id`, `access_token` (постоянный системный токен), `app_secret` (App → Settings → Basic), `verify_token` (любая случайная строка) | Meta for Developers → приложение → WhatsApp → Configuration → Callback URL = адрес, Verify token = тот же `verify_token`; подписаться на поле `messages` | по документации Cloud API |
| Viber (бот) | `token` (partners.viber.com) → Зберегти → «Демо» → «Зареєструвати вебхук» (`set_webhook`; Viber сразу проверит адрес) | никуда: регистрирует кнопка | по документации REST Bot API |
| Phonet | `domain`, `api_key`, `webhook_token` (придумайте длинную случайную строку) | кабинет Phonet → API/Webhooks: адрес + заголовок `X-Webhook-Token: <webhook_token>` | ⚠️ **временный**: поля событий взяты из публичного описания, на реальном аккаунте не проверены |
| Ringostat | `project_id`, `api_key`, `webhook_token`, `callback_extension` (внутренний номер для звонка из карточки) | Ringostat → Интеграции → Вебхуки, событие «после звонка»: адрес + заголовок `X-Webhook-Token`; параметры: `uniqueid, call_type, caller, dst, duration, recording, calldate, disposition` | ⚠️ **временный** (и вебхук, и callback API) |
| Binotel | `domain`, `api_key`, `webhook_token` | Binotel → API CALL COMPLETED: адрес + заголовок `X-Webhook-Token` | ⚠️ **временный** |

Адрес вебхука: `https://<домен>/api/webhooks/<ключ>` (ключ — `telegram_business`, `whatsapp_cloud`, `viber`, `phonet`,
`ringostat`, `binotel`). Точный адрес показан на странице — копируйте его оттуда.

## Как устроено

**Ошибки бизнес-правил** (DRY, 2026-10-08): `Exceptions/ChannelException` наследует `Core\Exceptions\BusinessRuleException` — общий конструктор (код, HTTP-статус, `extra`) и `render()` в JSON `{message, code, ...extra}`; модуль объявляет только именованные коды, ответ API прежний.

### Один путь приёма
```
провайдер ─POST /api/webhooks/{key}─► LimitWebhookBody (≤1 МБ) ─► throttle ─► WebhookService::receive
   статус off → 404 · adapter.verify() → 401 · adapter.parse(payload) → IncomingEvent[]
   → TouchpointIngestor::ingest(IncomingMessage)  (модуль Recruiting: дедуп, сопоставление, «Вхідні»)
демо: POST /api/channels/{key}/simulate → adapter.demoPayload() → тот же parse → тот же ingest (meta.demo)
отправка: POST /api/candidates/{id}/messages → MessageSender::send → тот же ingest (direction out, via_product)
```
Идемпотентность — по уникальному индексу `touchpoints(channel, external_id)`: повторная доставка, повтор после 5xx и
подделанный «replay» с тем же id не создают второго касания; гонка двух одинаковых запросов ловится на уникальном индексе
(`MatchingTouchpointIngestor` возвращает уже сохранённое касание). Сообщение, отправленное из карточки и вернувшееся
вебхуком (Telegram), тоже не дублируется: у него тот же `external_id`.

**Ветка разговора (`meta.thread`).** Адаптер передаёт id разговора: Telegram — `{business_connection_id}:{chat.id}`,
WhatsApp — `wa_id`, Viber — `sender.id`. Ingestor сначала ищет кандидата, уже привязанного к этой ветке в этом канале
(`TouchpointRepository::candidateIdByThread`), и только потом — по контакту. Поэтому Viber (не отдаёт телефон) после одной
ручной привязки во «Вхідних» дальше сам попадает в карточку. Ответ из карточки берёт ветку последнего касания канала
(`latestThreadOf`). Индекс — функциональный индекс MySQL 8.4 `touchpoints_channel_thread_index` по
`(channel, cast(json_unquote(json_extract(meta, '$."thread"')) as char(255)) collate utf8mb4_bin)` (PROD-50, ADR 0011;
миграция правлена на месте, её версия уже записана в применённых базах). Оптимизатор сопоставляет его с `where('meta->thread', …)`
Laravel; `meta.thread` длиннее 255 символов MySQL отклоняет. Тест: `tests/Feature/Channels/TouchpointThreadIndexTest`
(части индекса, `EXPLAIN` запроса `candidateIdByThread` — индекс в `possible_keys`, регистрозависимость треда, отказ на 256 символах).

### Адаптеры (`Adapters/`)
Интерфейс `Contracts/ChannelAdapter`: `key()`, `channel()`, `auth()`, `verify(Request, IntegrationConfig)`,
`parse(array, IntegrationConfig): list<IncomingEvent>`, `demoPayload(DemoSeed, IntegrationConfig)`, `acknowledge()`.
Дополнительные возможности — отдельные интерфейсы: `MessageSender` (`send`, `sendTest`), `WebhookRegistrar`,
`HandshakeResponder` (GET-проверка WhatsApp), `CallInitiator` (звонок из карточки).

| Адаптер | Подпись | Что становится касанием | Отправка |
|---|---|---|---|
| `TelegramBusinessAdapter` | `X-Telegram-Bot-Api-Secret-Token` = `webhook_secret` | `business_message`, `edited_business_message` (id `bc:msg:edit:<date>`). Направление: в личном чате `chat.id` = собеседник, поэтому `from.id == chat.id` → входящее, иначе написал владелец аккаунта или бот → исходящее (`via_product=false`). `business_connection` → только запись в журнал | `sendMessage` c `business_connection_id` в чат ветки; нет ветки → `no_conversation` |
| `WhatsappCloudAdapter` | `X-Hub-Signature-256` = `sha256=` HMAC тела ключом `app_secret`; GET `hub.mode/hub.verify_token/hub.challenge` | `messages` (текст, кнопки, подписи медиа; иначе `[тип]`), только своего `phone_number_id`; `statuses` — не касания, `failed` пишется в журнал `delivery_failed` с кодом | `POST /{phone_number_id}/messages`; последнее входящее старше 24 ч (или его нет) → `template_required` без запроса; ответ Graph API 131047/470 → тоже `template_required` |
| `ViberAdapter` | `X-Viber-Content-Signature` = HMAC тела ключом `token` | событие `message`; `webhook`, `delivered`, `seen`… — нет | `/pa/send_message` на `sender.id` ветки; статус 5/6 → `no_conversation` |
| `PhonetAdapter` ⚠️ | `X-Webhook-Token` / `Authorization: Bearer` = `webhook_token`, или `X-Signature` = HMAC тела ключом `webhook_token`; `?token=` — только за флагом `webhook_query_token` (см. ниже) | только `call.hangup` (`uuid`, `lgDirection` 2 = исходящий, `otherLegs[0].num`, `billSecs`/`duration`, `callUrl`) | click-to-call нет → `click_to_call_unsupported` |
| `RingostatAdapter` ⚠️ | то же | хук «после звонка» (`uniqueid`, `call_type` in/out/callback, `caller`/`dst`, `duration`, `recording`, `calldate`); принимаются и распространённые альтернативные имена | `CallInitiator`: `POST https://api.ringostat.net/callback/outward_call` (заголовок `Auth-key`, `extension` + `destination`) через SSRF-guard |
| `BinotelAdapter` ⚠️ | то же | `requestType=apiCallCompleted`, `callDetails[...]` (`generalCallID`, `callType` 0/1, `externalNumber`, `billsec`, `startTime`, ссылка на запись); ответ `{"status":"success"}` | нет |

Телефония: касание канала `call`, `meta.duration_sec`, `meta.recording_url` (только `https://`, **никогда не скачивается** —
загрузка записей отложена), `meta.call_status`. Текста нет, поэтому оценка по скрипту (Scripts) такие звонки пропускает
до подключения распознавания речи (Deepgram). Разбор терпимый (`Support/Payload`): первое найденное поле из списка
вариантов, время в секундах/миллисекундах/строкой (будущее → «сейчас»), неизвестный формат → 0 событий и 200.

### Режимы канала
`Enums/ChannelMode` из статуса интеграции: `off` → вебхуки 404, отправка `channel_not_connected`; `demo` → вебхуки
принимаются, отправка пишется без провайдера (`meta.demo`), доступна симуляция; `live` (`connected` или `error`) → реальные
вызовы. `connected` ставит только проверка соединения: у Telegram (`getMe`), WhatsApp (`GET /{phone_number_id}?fields=id`,
токен в заголовке) и Viber (`get_account_info`) она есть; у телефонии проверки нет, поэтому **звонок из карточки через
Ringostat станет доступен только после появления проверки** (см. «Не проверено»).

### Безопасность
- Подписи и токены сравниваются `hash_equals` в одном месте — `Support/WebhookCredentials` (токен в заголовке, HMAC-SHA256
  тела; им пользуются все адаптеры); секрет не задан → любой запрос 401. Неверная подпись/токен → **401** с
  `WWW-Authenticate: Bearer realm="webhooks"` (до HRM-26 было 403; GET-handshake WhatsApp по-прежнему 403). Отклонённый
  запрос пишется в журнал интеграции (`webhook_rejected`) без тела, заголовков и адреса; для `?token=` при выключенном
  флаге — с кодом `reason: query_token_disabled`.
- Тело > 1 МБ → 413 (`Http/Middleware/LimitWebhookBody`); лимит 300 запросов/мин на ключ+IP (`channel-webhooks`), отправка из
  карточки 30/мин, тест 10/мин, симуляция 30/мин, звонок 10/мин на пользователя.
- Секреты — только через `SecretVault` (`Integrations\Services\IntegrationConfigLoader`), в логах их нет: журнал хранит коды и
  счётчики, исключения HTTP-клиента не сохраняются (URL Telegram содержит токен) — `Support/ProviderHttp` превращает любой
  сбой в `send_failed`; общий `SecretScrubber` дополнительно чистит логи исключений.
- Любой исходящий URL проходит `OutboundUrlGuard::inspect()` (https, 443, только публичные IP — таблица запрещённых
  диапазонов в [integrations.md](integrations.md)), редиректы выключены, таймаут 10 с. `ProviderHttp` отправляет запрос
  с `PinnedTarget::httpOptions()`: соединение прибито к уже проверенным IP (`CURLOPT_RESOLVE`), имя повторно не
  резолвится, поэтому между проверкой и отправкой DNS нельзя перенаправить внутрь (DNS-rebinding). Заголовок `Host`,
  SNI и проверка сертификата не меняются — в URL остаётся имя хоста. Тест —
  `MessagesApiTest::test_outbound_provider_call_pins_the_connection_to_the_approved_ips`.
- Текст сообщений хранится как есть и выводится в интерфейсе как текст (Angular-интерполяция), **сырой HTML не рендерится**.
  Ссылка на запись разговора — только `https://`.
- Токен телефонии передаётся **заголовком**, не адресом: адрес запроса попадает в журналы доступа прокси/Vercel/провайдера.
  Старый `?token=` — только переходный вариант за флагом (раздел ниже). Внутри приложения адрес с query не пишется нигде:
  журнал интеграции хранит коды, `ErrorRecorder` сохраняет имя/шаблон маршрута (не URL), отказ 4xx не репортится, а
  `SecretScrubber` знает расшифрованный `webhook_token` и вычищает его из любого сообщения исключения.

### Аутентификация вебхуков телефонии (HRM-26)
Phonet, Ringostat, Binotel (`Adapters/AbstractTelephonyAdapter`, `auth = header_token`). Секрет — `webhook_token` в
хранилище секретов интеграции. Запрос принимается, если есть **одно** из:

| Способ | Что отправляет провайдер |
|---|---|
| Заголовок | `X-Webhook-Token: <webhook_token>` |
| Bearer | `Authorization: Bearer <webhook_token>` |
| Подпись тела | `X-Signature: <hex HMAC-SHA256(сырое тело, webhook_token)>` (допускается префикс `sha256=`) |

Сравнение — constant-time (`hash_equals`). Иначе 401.

**Переходный период.** Настройка интеграции `webhook_query_token` (поле «Застаріле: приймати токен в адресі», `off`/`on`):
- **новое подключение — `off`** (значение по умолчанию): любой запрос с `?token=` в адресе отклоняется 401, даже если
  токен верный и рядом есть правильный заголовок — так ошибочная настройка провайдера видна сразу, а токен не «утекает»
  молча в журналы; в журнале интеграции — `webhook_rejected` с `reason: query_token_disabled`;
- **существующее подключение — `on`**: миграция `2026_10_09_100001_keep_query_token_for_existing_telephony` включила флаг
  всем интеграциям телефонии, у которых уже сохранён `webhook_token` (явно заданное значение не трогает), чтобы звонки не
  потерялись в день выката. Пока флаг `on`, `?token=` принимается, но помечается устаревшим: в ответе заголовок
  `Deprecation: @1791504000` (RFC 9745), в журнале — `webhook_received` уровня warning с `auth: query_token_deprecated`,
  на странице «Інтеграції» — предупреждение в блоке «Вебхук». Заголовок работает и при включённом флаге.

**Как отключить `?token=`:** в кабинете провайдера оставьте адрес без `?token=` и добавьте заголовок
`X-Webhook-Token` (или Bearer / `X-Signature`) → дождитесь звонка и убедитесь, что в «Останні події» нет предупреждения →
в карточке интеграции поле «Застаріле: приймати токен в адресі» = «Вимкнено» → «Зберегти». Обратно включить можно тем же
полем (только временно). Сменить сам токен — новое значение в поле «Токен вебхука» и то же в кабинете провайдера.

### Эндпоинты
| Метод и путь | Доступ | Тело | Ответ |
|---|---|---|---|
| `POST /api/webhooks/{key}` | провайдер (подпись / токен в заголовке) | формат провайдера | 200 `acknowledge()` (+ `Deprecation`, если принят по устаревшему `?token=`); неизвестный ключ / выключено → 404; подпись/токен → 401; > 1 МБ → 413 |
| `GET /api/webhooks/{key}` | Meta (verify token) | `hub.*` | 200 `text/plain` challenge; иначе 403/404 |
| `GET /api/channels` | любой активный | — | `{data: [{key, channel, mode, reason?}]}` для мессенджеров, телефонии и e-mail (`google_gmail`: `live`, если Gmail подключён с `gmail.send`, иначе `off` и `reason: not_connected \| reconnect_to_send`) |
| `POST /api/candidates/{id}/messages` | `CandidatePolicy::update` | `{channel: telegram\|whatsapp\|viber\|email, text ≤ 4096, application_id?, subject? ≤ 255 (только email)}` | 201 касание; 422 `channel_not_connected \| no_conversation \| template_required \| invalid_recipient \| application_mismatch`; 429 `rate_limited` (email, > 60 писем/час на ящик); 502 `send_failed` |
| `POST /api/candidates/{id}/call` | `CandidatePolicy::update` | — | 202 `{status: requested, integration}`; 422 `telephony_not_connected \| click_to_call_unsupported \| no_phone`; сам звонок придёт вебхуком |
| `GET /api/channels/admin` | суперадмин | — | `{data: [{key, channel, mode, webhook_url, auth, can_register, can_send, can_call, handshake}]}` |
| `POST /api/channels/{key}/register-webhook` | суперадмин | — | `{data: {registered: true}}`; 422 `channel_off \| unsupported \| channel_not_connected`; 502 `send_failed` (старый секрет сохраняется) |
| `POST /api/channels/{key}/test` | суперадмин | `{to, text?}` | `{data: {sent, external_id}}`; касание не создаётся |
| `POST /api/channels/{key}/simulate` | суперадмин, только статус `demo` | `{candidate_id?, contact?, text?}` | 201 `{data: {events, created, touchpoints}}`; не демо → 422 `not_demo` |

### Слои и файлы
`Http/Controllers/{WebhookController, ChannelMessageController, ChannelAdminController}` → `Http/Requests/*` →
`Services/{WebhookService, MessageService, CallService, ChannelAdminService, DemoSeedFactory, ChannelContext}` →
адаптеры + контракты Recruiting (`TouchpointIngestor`, `TouchpointRepository`, `ApplicationRepository`) и Integrations
(`IntegrationConfigLoader`, `IntegrationRepository`, `SecretVault`). Реестр `Support/ChannelRegistry` (тег
`channels.adapters`). **Новый канал** = класс адаптера + строка в `ChannelsServiceProvider::ADAPTERS` (+ определение
интеграции в модуле Integrations).

### Фронтенд (`frontend/src/app/features/channels`)
`channels.model.ts`, `channels.service.ts` (HTTP, доступность каналов — один запрос на сессию, `channelErrorKey`,
`tokenHeaderForConsole` — строка `X-Webhook-Token: <заглушка>` для телефонии), `channel-panel.ts` — блок «Вебхук» в карточке интеграции (`features/integrations/integration-card`).
Отправка — в `features/recruiting/card/touch-composer.ts` (кнопка «Надіслати», запасной путь «Записати вручну»). Строки —
`channels.*` и `integrations.logs.messages.*`, `integrations.fields.*` в `public/i18n/{uk,ru,en}.json`.

**Вид (рестайл C «Маршрут», 2026-10-02).** Разделитель панели канала — «трек» 1.5px (`--app-track`), без hex и линий 1px. Тест вида — `features/channels/channels.restyle.spec.ts` (контракт стилей: только токены темы, без hex, линии 1.5px, без «бледности» через opacity).

### Общие хелперы Core (2026-10-02)
- текущий пользователь в контроллерах — общий трейт `Core\Http\Concerns\ResolvesActor` вместо приватной копии `actor()` (`ChannelAdminController`, `ChannelMessageController`).

Поведение API не менялось; подробности — [core.md](core.md), раздел «Общие хелперы модулей».

### Общие примитивы фронта
Общий код фронта лежит в `frontend/src/app/core` ([core.md](core.md)); фича его только вызывает.
- Ошибки: `channelErrorCode`/`channelErrorKey` читают код и статус ответа общими `apiErrorCode`/`apiErrorStatus` (`core/api/api-error.ts`); 403 по-прежнему показывает `recruiting.errors.forbidden` (кандидат вне области доступа).
- Короткие уведомления (toast) — `NotifyService.show(key, { params?, duration? })` из `core/ui/notify.service.ts` вместо своего `toast()` с `MatSnackBar`; тексты, длительности и доступность (вежливая live-область snack bar) прежние.
- HTTP-сервис фичи снимает обёртку ответа `{ data }` общим оператором `unwrapData()` (`core/api/unwrap-data.ts`, тип `DataEnvelope<T>` из `core/api/api.model.ts`) вместо своего `map((r) => r.data)`; параметры запроса без пустых значений — `toParams` из `core/api/http-params.ts`, страница списка — `Paged<T>` оттуда же. Контракт API не менялся.

### Зависимости через контракты (2026-10-08)
- `ChannelContext` берёт режим канала и его конфиг через контракт Integrations `IntegrationConfigs` (не через класс `IntegrationConfigLoader`). Тест — `tests/Unit/Channels/ChannelContextTest.php`.

## Как проверить
Бэкенд: `tests/Feature/Channels/WebhookApiTest.php` (404 неизвестного/выключенного, 401 на неверный/отсутствующий секрет
для каждого провайдера; телефония HRM-26: `test_telephony_token_in_header_bearer_or_body_signature`,
`test_query_token_is_401_while_the_transitional_flag_is_off`, `test_query_token_is_accepted_and_marked_deprecated_while_the_flag_is_on`,
`test_telephony_token_never_reaches_any_log`, `test_telephony_without_configured_token_rejects_everything`; сопоставление по @username/телефону, «Вхідні», идемпотентность, исходящее от владельца в Telegram,
handshake WhatsApp, статусы и `delivery_failed`, чужой `phone_number_id`, Viber, звонки Phonet/Binotel (form)/Ringostat,
небезопасная ссылка на запись, 413, журнал без секретов и текста), `MessagesApiTest.php` (права, валидация, off, демо без
HTTP, Telegram в известный чат и без чата, окно 24 ч WhatsApp и код 131047, Viber + 502, чужая заявка, доступность каналов,
click-to-call: нет телефонии / не поддерживается / Ringostat через `Http::fake`), `ChannelAdminApiTest.php` (только
суперадмин, адреса, `legacy_query_token` без значения токена, регистрация Telegram с новым секретом и сохранение старого при ошибке, Viber, тест, симуляция всех 6
каналов только в демо, симуляция от существующего кандидата), `tests/Feature/Integrations/MessengerChecksTest.php`,
`tests/Unit/Channels/AdaptersParseTest.php`, `tests/Unit/Channels/WebhookCredentialsTest.php` (все способы токена и флаг),
`tests/Feature/Channels/KeepQueryTokenMigrationTest.php` (флаг `on` только существующим), `tests/Unit/Recruiting/TouchpointIngestorTest.php` (ветка разговора, гонка).
Фронт: `channels.service.spec.ts`, `channel-panel.spec.ts` (строка заголовка с заглушкой, предупреждение, без `?token=`).

Вручную (preview, без сессии):
```bash
curl -i -X POST "https://<preview>/api/webhooks/nope"                       # 404
curl -i -X POST "https://<preview>/api/webhooks/telegram_business" -d '{}'  # 404, пока интеграция выключена; 401 в демо без секрета
# телефония в демо (подставьте свой токен из поля «Токен вебхука»; в репозиторий/чат его не копировать):
curl -i -X POST "https://<preview>/api/webhooks/phonet" -H "X-Webhook-Token: $PHONET_WEBHOOK_TOKEN" -H 'Content-Type: application/json' -d '{"event":"call.dial","uuid":"t1"}'  # 200
curl -i -X POST "https://<preview>/api/webhooks/phonet?token=x" -H 'Content-Type: application/json' -d '{}'  # 401, пока флаг webhook_query_token выключен
```

**Не проверено:** ни один провайдер не вызывался вживую (токенов нет) — форматы Telegram/WhatsApp/Viber взяты из публичной
документации, телефония (Phonet, Ringostat, Binotel, callback Ringostat) — **временные** предположения; доставка вебхуков
до `sinhrm.vercel.app` через rewrite Vercel; скорость ответа на холодном старте (провайдеры ждут 5–20 с — должно хватать).


## Идентификаторы звонков
Три провайдера телефонии пишут в один канал `call`, поэтому `external_id` звонка хранится с префиксом провайдера (`phonet:<id>`, `binotel:<id>`, `ringostat:<id>`) — одинаковые id у разных провайдеров не склеиваются.

## Доступ к модулю

Ключ модуля `channels`. Суперадмин может выключить модуль для всей компании или скрыть его от части ролей на странице «Адміністрування → Модулі». По умолчанию: включён, роли — все роли (как и до появления выключателя). Выключенный модуль отвечает 403 `module_disabled`, его фоновые задачи пропускаются, данные не удаляются. Если модуль выключен, вебхуки провайдеров отвечают 404 (сообщения не принимаются, уже полученные остаются). Подробнее — [modules-access.md](modules-access.md).
