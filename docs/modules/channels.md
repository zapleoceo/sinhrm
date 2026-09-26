# Модуль Channels (мессенджеры и телефония)

## Что это и зачем
Кандидаты пишут рекрутеру в Telegram, WhatsApp, Viber и звонят. Модуль Channels приносит эти сообщения и звонки **в карточку
кандидата сами** — рекрутеру не нужно переписывать переписку руками. Если по телефону, e-mail или @username понятно, чей это
кандидат, касание попадает в его ленту; если нет — во «Вхідні», где его привязывают одним кликом. Дальше вся переписка
этого чата сама идёт к тому же кандидату.

Из карточки можно **отправить** сообщение в Telegram / WhatsApp / Viber: оно уходит через подключённый канал и сразу
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
копирования, как проверяется подлинность, короткая инструкция, кнопки **«Зареєструвати вебхук»** (Telegram, Viber),
**«Надіслати тест»** (на chat id / телефон / Viber id, который вы вводите) и **«Демо-подія»** (только в режиме «Демо»).
Ниже — «Останні події»: принятые вебхуки, отклонённые подписи, регистрация, тесты, ошибки отправки (только коды, без текстов).

### Шаги владельца по провайдерам
| Провайдер | Что ввести в «Інтеграції» | Куда вставить адрес | Статус формата |
|---|---|---|---|
| Telegram Business | `bot_token` (от @BotFather) → Зберегти → режим «Демо» → «Зареєструвати вебхук» (система сама вызовет `setWebhook` и создаст `webhook_secret`) | никуда: регистрирует кнопка. Затем в Telegram рекрутера: Настройки → Telegram Business → Чат-боты → добавить бота **с правом отвечать** | по документации Bot API |
| WhatsApp Cloud | `phone_number_id`, `waba_id`, `access_token` (постоянный системный токен), `app_secret` (App → Settings → Basic), `verify_token` (любая случайная строка) | Meta for Developers → приложение → WhatsApp → Configuration → Callback URL = адрес, Verify token = тот же `verify_token`; подписаться на поле `messages` | по документации Cloud API |
| Viber (бот) | `token` (partners.viber.com) → Зберегти → «Демо» → «Зареєструвати вебхук» (`set_webhook`; Viber сразу проверит адрес) | никуда: регистрирует кнопка | по документации REST Bot API |
| Phonet | `domain`, `api_key`, `webhook_token` (придумайте длинную случайную строку) | кабинет Phonet → API/Webhooks: адрес + `?token=<webhook_token>` | ⚠️ **временный**: поля событий взяты из публичного описания, на реальном аккаунте не проверены |
| Ringostat | `project_id`, `api_key`, `webhook_token`, `callback_extension` (внутренний номер для звонка из карточки) | Ringostat → Интеграции → Вебхуки, событие «после звонка»: адрес + `?token=…`; параметры: `uniqueid, call_type, caller, dst, duration, recording, calldate, disposition` | ⚠️ **временный** (и вебхук, и callback API) |
| Binotel | `domain`, `api_key`, `webhook_token` | Binotel → API CALL COMPLETED: адрес + `?token=…` | ⚠️ **временный** |

Адрес вебхука: `https://<домен>/api/webhooks/<ключ>` (ключ — `telegram_business`, `whatsapp_cloud`, `viber`, `phonet`,
`ringostat`, `binotel`). Точный адрес показан на странице — копируйте его оттуда.

## Как устроено
### Один путь приёма
```
провайдер ─POST /api/webhooks/{key}─► LimitWebhookBody (≤1 МБ) ─► throttle ─► WebhookService::receive
   статус off → 404 · adapter.verify() → 403 · adapter.parse(payload) → IncomingEvent[]
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
(`latestThreadOf`). Для Postgres есть индекс по `(channel, meta->>'thread')` (миграция модуля).

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
| `PhonetAdapter` ⚠️ | `?token=` = `webhook_token` (`hash_equals`) | только `call.hangup` (`uuid`, `lgDirection` 2 = исходящий, `otherLegs[0].num`, `billSecs`/`duration`, `callUrl`) | click-to-call нет → `click_to_call_unsupported` |
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
- Подписи сравниваются `hash_equals`; секрет не задан → любой запрос 403. Отклонённый запрос пишется в журнал интеграции
  (`webhook_rejected`) без тела и заголовков.
- Тело > 1 МБ → 413 (`Http/Middleware/LimitWebhookBody`); лимит 300 запросов/мин на ключ+IP (`channel-webhooks`), отправка из
  карточки 30/мин, тест 10/мин, звонок 10/мин на пользователя.
- Секреты — только через `SecretVault` (`Integrations\Services\IntegrationConfigLoader`), в логах их нет: журнал хранит коды и
  счётчики, исключения HTTP-клиента не сохраняются (URL Telegram содержит токен) — `Support/ProviderHttp` превращает любой
  сбой в `send_failed`; общий `SecretScrubber` дополнительно чистит логи исключений.
- Любой исходящий URL проходит `OutboundUrlGuard` (https, 443, только публичные IP), редиректы выключены, таймаут 10 с.
- Текст сообщений хранится как есть и выводится в интерфейсе как текст (Angular-интерполяция), **сырой HTML не рендерится**.
  Ссылка на запись разговора — только `https://`.
- `?token=` в адресе телефонии виден в логах доступа провайдера/Vercel — это временная схема, пока не подтверждены
  собственные подписи провайдеров.

### Эндпоинты
| Метод и путь | Доступ | Тело | Ответ |
|---|---|---|---|
| `POST /api/webhooks/{key}` | провайдер (подпись) | формат провайдера | 200 `acknowledge()`; неизвестный ключ / выключено → 404; подпись → 403; > 1 МБ → 413 |
| `GET /api/webhooks/{key}` | Meta (verify token) | `hub.*` | 200 `text/plain` challenge; иначе 403/404 |
| `GET /api/channels` | любой активный | — | `{data: [{key, channel, mode}]}` для мессенджеров и телефонии |
| `POST /api/candidates/{id}/messages` | `CandidatePolicy::update` | `{channel: telegram\|whatsapp\|viber, text ≤ 4096, application_id?}` | 201 касание; 422 `channel_not_connected \| no_conversation \| template_required \| invalid_recipient \| application_mismatch`; 502 `send_failed` |
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
`webhookUrlForConsole`), `channel-panel.ts` — блок «Вебхук» в карточке интеграции (`features/integrations/integration-card`).
Отправка — в `features/recruiting/card/touch-composer.ts` (кнопка «Надіслати», запасной путь «Записати вручну»). Строки —
`channels.*` и `integrations.logs.messages.*`, `integrations.fields.*` в `public/i18n/{uk,ru,en}.json`.

## Как проверить
Бэкенд: `tests/Feature/Channels/WebhookApiTest.php` (404 неизвестного/выключенного, 403 на неверный/отсутствующий секрет
для каждого провайдера, сопоставление по @username/телефону, «Вхідні», идемпотентность, исходящее от владельца в Telegram,
handshake WhatsApp, статусы и `delivery_failed`, чужой `phone_number_id`, Viber, звонки Phonet/Binotel (form)/Ringostat,
небезопасная ссылка на запись, 413, журнал без секретов и текста), `MessagesApiTest.php` (права, валидация, off, демо без
HTTP, Telegram в известный чат и без чата, окно 24 ч WhatsApp и код 131047, Viber + 502, чужая заявка, доступность каналов,
click-to-call: нет телефонии / не поддерживается / Ringostat через `Http::fake`), `ChannelAdminApiTest.php` (только
суперадмин, адреса, регистрация Telegram с новым секретом и сохранение старого при ошибке, Viber, тест, симуляция всех 6
каналов только в демо, симуляция от существующего кандидата), `tests/Feature/Integrations/MessengerChecksTest.php`,
`tests/Unit/Channels/AdaptersParseTest.php`, `tests/Unit/Recruiting/TouchpointIngestorTest.php` (ветка разговора, гонка).
Фронт: `channels.service.spec.ts`.

Вручную (preview, без сессии):
```bash
curl -i -X POST "https://<preview>/api/webhooks/nope"                       # 404
curl -i -X POST "https://<preview>/api/webhooks/telegram_business" -d '{}'  # 404, пока интеграция выключена; 403 в демо без секрета
```

**Не проверено:** ни один провайдер не вызывался вживую (токенов нет) — форматы Telegram/WhatsApp/Viber взяты из публичной
документации, телефония (Phonet, Ringostat, Binotel, callback Ringostat) — **временные** предположения; доставка вебхуков
до `sinhrm.vercel.app` через rewrite Vercel; скорость ответа на холодном старте (провайдеры ждут 5–20 с — должно хватать).
