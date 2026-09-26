# Модуль GoogleWorkspace — подключение Google, встречи в Календаре, импорт из Google Sheets

## Что это и зачем
SinHRM умеет работать с **одним Google-аккаунтом компании** (например, общим ящиком рекрутинга):
- **Gmail** — читать входящие письма (их разбирает [почтовый агент](mail-agent.md)) и в будущем отправлять;
- **Календарь** — из карточки кандидата создать встречу (онлайн — сразу со ссылкой Google Meet);
- **Таблицы** — загрузить кандидатов из Google-таблицы (например, ответы Google Form или выгрузку рекламы).

Подключение делает суперадмин **отдельно от входа в систему**: вход через Google даёт только имя и e-mail сотрудника,
а здесь компания разрешает SinHRM доступ к ящику, календарю и таблицам. Ключи доступа (токены) хранятся только в базе,
в зашифрованном виде, и никому не показываются — ни в интерфейсе, ни в ответах API, ни в логах.

## Как пользоваться
**Подключить (суперадмин):** Адміністрування → Інтеграції → группа Google → **«Підключити Google»** → Google спросит
разрешения (почта: чтение и отправка; календарь: события; таблицы: чтение) → после согласия вернёт на страницу интеграций
с сообщением «Google підключено». Если какую-то галочку на экране Google сняли, этот сервис останется «Не підключено» (будет
сообщение «Не надано доступ до: …»). Повторное подключение («Перепідключити») безопасно — токены просто заменяются.

**Если Google отозвал доступ** (пароль сменили, доступ отозван, или истекли 7 дней в режиме Testing — см. ниже) —
на главной у суперадмина появится предупреждение «Google (…) потребує перепідключення», а статус сервиса станет «Помилка».
Нужно снова нажать «Перепідключити».

**Встреча (рекрутер, админ, суперадмин — для кандидатов, которых он видит):** карточка кандидата → **«Запланувати
зустріч»** → дата, время, длительность, формат «Онлайн (Google Meet)» или «У філії» (+ место), заметки, галочка «Додати
кандидата до учасників». Событие создаётся в календаре подключённого аккаунта; рекрутер добавляется участником. **Google
никому не отправляет приглашения** — после создания диалог показывает ссылку Meet с кнопкой «Копіювати», её можно отправить
кандидату самому. В ленте кандидата появляется касание «Зустріч» с временем и ссылками. Если календарь не подключён, кнопка
неактивна с подсказкой.

**Импорт из Google Sheets (суперадмин):** Адміністрування → **Імпорт з Google Sheets** → вставить ссылку на таблицу
(`https://docs.google.com/spreadsheets/d/…`; таблица должна быть доступна подключённому аккаунту) → «Прочитати заголовки» →
система сама предложит, какая колонка — ФИО, телефон, e-mail, Telegram, источник, `utm_*`, вакансия, дата заявки; можно
поправить → превью первых 10 строк → «Імпортувати». Итог: создано / уже были / пропущено / добавлено к вакансиям /
вакансия не найдена / ошибки по строкам (номер строки и причина, без значений ячеек). Импорт запоминается: «Довантажити
нові» загрузит только строки ниже последней обработанной, а переключатель «Кожні 30 хв» делает это автоматически.

## Шаги владельца (однократно, вне кода)
1. **Добавить адрес возврата** в Google Cloud Console → APIs & Services → Credentials → OAuth-клиент **входа** (тот же, что
   `GOOGLE_CLIENT_ID`) → Authorized redirect URIs: `https://sinhrm.vercel.app/api/google/connect/callback`.
   Без этого Google покажет `redirect_uri_mismatch`. Адрес также виден на странице интеграций (подсказка под кнопкой).
2. **Включить API** в том же проекте Google Cloud: Gmail API, Google Calendar API, Google Sheets API.
3. **OAuth consent screen**: добавить scopes `gmail.readonly` (только чтение — агент почту не отправляет), `calendar.events`, `spreadsheets.readonly`
   (они «sensitive/restricted»). Пока приложение в режиме **Testing**, аккаунт ящика должен быть в списке test users, а
   **refresh token живёт 7 дней** — раз в неделю нужно «Перепідключити» (система предупредит на главной). Чтобы снять
   ограничение — перевести приложение в Production (для Gmail-scopes Google требует верификацию).

## Как устроено
### OAuth-подключение (`Http/Controllers/GoogleConnectController`, `routes.web.php`, группа `web`)
- `GET /api/google/connect?services=gmail,calendar,sheets` (по умолчанию все три; неизвестные имена игнорируются) —
  суперадмин (`auth:sanctum` + активный + `can:manage-integrations`). Генерирует `state` (40 символов), кладёт в сессию
  `google_connect = {state, services}` и перенаправляет на `https://accounts.google.com/o/oauth2/v2/auth` с
  `access_type=offline`, `prompt=consent` (Google всегда вернёт refresh token), `include_granted_scopes=true` и scopes
  `openid email` + scopes сервисов (`Enums/GoogleService::scopes()`).
- `GET /api/google/connect/callback?code&state` — `state` из сессии забирается (одноразовый) и сравнивается `hash_equals`;
  нет/не совпал → `/admin/integrations?google_error=invalid_state` **без запроса в Google**; `?error=…` →
  `google_error=consent_denied`. Иначе `Services/GoogleConnectService::complete()` меняет код на токены
  (`POST https://oauth2.googleapis.com/token`, `grant_type=authorization_code`, **тот же OAuth-клиент, что и вход** —
  `config('services.google')`, redirect URI — `services.google.connect_redirect`, env `GOOGLE_CONNECT_REDIRECT_URI`, по
  умолчанию prod-адрес выше). Сервис считается подключённым, только если **все** его scopes есть в ответе `scope`;
  иначе он попадает в `missing` → `?connected=google&missing=calendar`. E-mail аккаунта берётся из `id_token` (получен
  напрямую от Google по TLS, подпись не перепроверяется — используется только как подпись в интерфейсе).
- В URL и лог попадают только коды (`google.connect_failed {code}`), никогда код авторизации или токены.

### Хранение (`Services/GoogleConnectionStore` поверх модуля Integrations)
| Где | Что |
|---|---|
| `integration_secrets` через `SecretVault` (шифрование `APP_KEY`) | `refresh_token`, `access_token` для ключей `google_gmail`, `google_calendar`, `google_sheets` (один grant хранится у каждого подключённого сервиса) |
| `integrations.status` | `connected` после подключения; `error` + `last_error = reconnect_required` после `invalid_grant` |
| `integrations.settings` | `account_email`, `scopes`, `connected_by` (id суперадмина), `connected_at`, `access_expires_at` — не секреты; в UI интеграций не редактируются (у Google-определений нет полей) |
| `integration_logs` | `google_connected {by, scopes}`, `reconnect_required`, `sheets_imported {счётчики}` — без значений |

Сервис «пригоден» (`ConnectionState::usable`), когда статус `connected` и refresh token есть. Ручное переключение
статуса на `off`/`demo` в интеграциях выключает использование до нового подключения.

### Токены (`Contracts/GoogleTokenProvider` → `Services/GoogleTokenService`)
Кэш — `access_token` в vault + `access_expires_at`; если осталось ≥ 60 с — используется без запроса. Иначе
`grant_type=refresh_token`, новый токен сохраняется (и сообщается `SecretScrubber`). Ответ `400 {"error":"invalid_grant"}`
(отозван, истёк, 7 дней Testing) → `markReconnectRequired()`: статус `error`, код `reconnect_required`, лог-предупреждение,
кэш стёрт; `GoogleException::reconnectRequired()` (HTTP 409). Пока код стоит, повторных запросов в Google нет.
Предупреждение на главной — `Services/GoogleDashboardNotices` (контракт `Overview\Contracts\DashboardNotices`, только
суперадмину).

### Вызовы Google (`Support/GoogleApi`) — без `google/apiclient`
Пакет Google слишком тяжёлый для serverless-бандла, поэтому REST вызывается Laravel HTTP-клиентом: `Bearer`-токен,
таймауты 15/5 с, **редиректы запрещены**, хосты — константы (`gmail.googleapis.com`, `www.googleapis.com`,
`sheets.googleapis.com`), не ввод пользователя, поэтому `OutboundUrlGuard` не нужен. 401 → токен сбрасывается и запрос
повторяется **один раз**. Ошибки → коды `google_unauthorized | google_forbidden | google_not_found | google_http_<N> |
google_unreachable | google_bad_response` (`Exceptions/GoogleException`, тело ответа Google не логируется).

| Клиент | Вызов |
|---|---|
| `Services/GoogleGmailClient` (`Contracts/GmailClient`) | `GET /gmail/v1/users/me/messages?q=…&maxResults=…`, `GET …/messages/{id}?format=full` → `DTO/GmailMessage` (From, Subject, текст через `Support/MimeText`: предпочтительно `text/plain`, иначе HTML → текст без `script/style`, ссылки сохраняются; base64url, перекодировка charset) |
| `Services/GoogleCalendarClient` (`Contracts/CalendarClient`) | `POST /calendar/v3/calendars/primary/events?conferenceDataVersion=1|0&sendUpdates=none`; онлайн — `conferenceData.createRequest {requestId: uuid, conferenceSolutionKey: hangoutsMeet}` |
| `Services/GoogleSheetsClient` (`Contracts/SheetsClient`) | `GET /v4/spreadsheets/{id}/values/{A1-range}?majorDimension=ROWS` |

### Встречи (`Services/MeetingService`, `Http/Controllers/MeetingController`)
`POST /api/google/candidates/{candidate}/meetings` `{title, start (ISO 8601 с часовым поясом), duration_minutes 15..480,
type: online|branch, invite_candidate?, location?, notes?}` — доступ как на редактирование кандидата
(`CandidatePolicy::update`: рекрутер в своих филиалах, админ, суперадмин; viewer и чужой филиал → 403). Календарь не
подключён → 422 `google_calendar_not_connected`. Участники: e-mail рекрутера и (по галочке) кандидата. Ответ 201
`{data: {event_id, meet_link, html_link, touchpoint}}`. Касание — через `Recruiting\Services\TouchpointService::log()`
(канал `meeting`, исходящее, `via_product`, `occurred_at` = момент планирования, в `meta`: `event_id, meet_link, html_link,
start, end, meeting_type, title`). Ошибка Google → касание не создаётся. `GET /api/google/calendar` → `{data: {connected}}`
(любой активный пользователь; карточка решает, активна ли кнопка).

### Импорт из Google Sheets (`Services/SheetsImportService`, `Http/Controllers/SheetsImportController`)
Таблица `sheet_imports` (миграция `2026_09_29_100001`): `spreadsheet_id, sheet ('' = первый лист), headers, mapping
{поле: номер колонки}, last_row (1 = только заголовок), auto_sync, created_by, last_synced_at, last_report`,
`unique(spreadsheet_id, sheet)`.

| Метод и путь (суперадмин) | Что |
|---|---|
| `POST /api/google/sheets/inspect {url, sheet?}` | строки 1..11 (`A1:Z11`): `headers`, `rows` (10), `suggested` (по названиям колонок uk/ru/en: точное совпадение, затем вхождение; `utm_*`, Telegram и e-mail раньше телефона и имени) |
| `POST /api/google/sheets/imports {url, sheet?, mapping, auto_sync?}` | сохраняет (или обновляет) импорт и сразу запускает → `{data, report}` |
| `PATCH /api/google/sheets/imports/{id} {auto_sync?, mapping?}` | неизвестные поля → 422; колонки вне заголовка отбрасываются |
| `POST /api/google/sheets/imports/{id}/run` | строки после `last_row`, не больше 500 за запуск, бюджет 40 с |
| `GET /api/google/sheets/imports` | список |

Строка → `Recruiting\DTO\CandidateData` (источник — значение колонки, если это известный источник, иначе `import`;
`utm_*` → `utm`; способ добавления `added_via = sheets`, канал привлечения — по UTM-колонкам или источнику,
[acquisition-channels.md](acquisition-channels.md)) → `Recruiting\Services\CandidateService::createOrMatch()`: совпадение по нормализованному телефону /
e-mail / Telegram (глобально) — **matched**, иначе **created** (нет ФИО → ошибка `full_name_required`); колонка
«вакансия» → открытая вакансия с таким же названием без учёта регистра (одна; иначе `vacancy_unmatched`) → заявка на
первом этапе с датой из «дата заявки» (если это дата не из будущего). Пустые строки и строки без контактов — **skipped**.
Отчёт: `created, matched, skipped, applied, vacancy_unmatched, errors[{row, code}] (≤100), last_row, rows` — **без
значений ячеек**. Задача `sheets.sync` (`Services/SheetsSyncJob`, cron каждые 30 мин) повторяет импорты с `auto_sync`
от имени того, кто их сохранил (если он ещё активен); Таблицы не подключены → `{skipped: not_connected}`.

### Фронтенд (`frontend/src/app/features/google-workspace`)
| Файл | Что |
|---|---|
| `google.model.ts`, `google.service.ts` | типы, HTTP, `googleErrorKey`, `connectUrl`, `isSheetUrl`, `toIsoWithOffset` (дата+время браузера → ISO с его смещением) |
| `google-connect.panel.ts` | блок в «Інтеграціях» (группа Google): состояние сервисов, кнопка подключения (переход браузера, не XHR), результат `?connected=`/`?google_error=` (параметры затем убираются из адреса), адрес возврата и подсказка про 7 дней |
| `meeting.dialog.ts` | диалог «Запланувати зустріч» (кнопка — в `recruiting/card/candidate-card`) |
| `sheets-import.page.ts` | `/admin/sheets-import` (суперадмин) |

Строки — `google.*` в `public/i18n/{uk,ru,en}.json`.

**Интерфейс (2026-09-26):** Даты вводятся только выпадающим календарём Angular Material (формат дд.мм.рррр, неделя с понедельника; [core.md](core.md)), в API уходит прежний `YYYY-MM-DD` (`core/date/iso-date.ts`, без сдвига часового пояса); в «Запланувати зустріч» день — календарь, время — `mat-timepicker` (шаг 15 мин, 24 ч), в API по-прежнему `toIsoWithOffset(date, time)`. Сервисы Gmail / Calendar / Sheets в панели подключения показаны иконками Font Awesome (`app-channel-icon`).

## Как проверить
Бэкенд (Google везде подменён `Http::fake`, `Http::preventStrayRequests()`; все значения синтетические):
- `tests/Feature/GoogleWorkspace/GoogleConnectTest` — 401/403; redirect: scopes, `offline`, `consent`, state в сессии,
  секрет клиента не в URL; state неверный/отсутствует/повторный → без запроса к Google; отказ; обмен кода (поля запроса),
  токены только в vault (шифротекст в БД), статус `connected`, e-mail из `id_token`, **ни токена, ни кода** в ответах
  `/api/google/status` и `/api/integrations`, в `integration_logs` и в логах; частичное согласие → `missing`.
- `GoogleTokenTest` — не подключён; кэш без запроса; refresh и кэширование; `invalid_grant` → `reconnect_required` +
  предупреждение на главной (только суперадмину) и без повторных запросов; 401 → один повтор с новым токеном.
- `MeetingTest` — не подключён (422, без запросов); онлайн: `conferenceDataVersion=1`, `sendUpdates=none`, Meet,
  участники, касание с `meta`; «в филиале» без конференции; доступ (чужой филиал, viewer); валидация; ошибка Google → 502
  без касания.
- `SheetsImportTest` — доступ; URL; inspect + предложенное сопоставление; импорт (создан / найден / пропущен / ошибка
  строки / вакансия), отчёт без значений; повторный запуск только новых строк; PATCH; `sheets.sync`.
- Unit: `tests/Unit/GoogleWorkspace/GoogleSupportTest` (MIME, HTML → текст, адреса, URL таблицы, диапазоны, подсказки
  колонок, `id_token`).

Фронт: `google.spec.ts`. Вручную на prod (нужны шаги владельца выше): Інтеграції → «Підключити Google» → согласие →
«Google підключено»; карточка кандидата → «Запланувати зустріч» → событие в календаре ящика; импорт тестовой таблицы
с синтетическими строками.
```bash
curl -i https://sinhrm.vercel.app/api/google/status          # без сессии → 401
curl -i https://sinhrm.vercel.app/api/google/connect         # без сессии → 401
```
