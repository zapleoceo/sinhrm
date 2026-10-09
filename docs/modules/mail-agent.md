# Модуль MailAgent — почтовый агент (разбор Gmail)

## Что это и зачем
Отклики с сайтов вакансий (work.ua, robota.ua, Djinni, формы сайта) приходят на почту, и рекрутер переносит их в систему
руками. У этих сайтов нет открытого API для работодателя, поэтому почта (плюс браузерное расширение для профилей,
[extension.md](extension.md)) — единственный путь; карточек-интеграций для них нет ([integrations.md](integrations.md)).
Почтовый агент делает это сам: каждые 30 минут читает подключённый ящик Gmail и
- **отклик с сайта вакансий** → находит или создаёт кандидата (без дублей), ставит его на вакансию и создаёт рекрутеру
  вакансии задачу **«Зателефонувати новому кандидату» со сроком 1 час** от получения письма;
- **письмо кандидата** (адрес совпал с e-mail кандидата) → касание «E-mail» в его карточке;
- **рассылки, коллеги, «игнорировать»** → пропускаются;
- **незнакомый отправитель** → попадает в очередь «Невідомі відправники», где суперадмин одним кликом говорит, кто это
  (и для одного адреса, и сразу для всего домена). Из таких писем сохраняется **только адрес и тема**, текст — нет.

Решение «что это за письмо» принимается **по правилам отправителей**. ШІ помогает только с незнакомыми отправителями
(решение владельца): для нового адреса в очереди ШІ получает адрес, тему и начало письма и отвечает, кто это. Если он
уверен (≥ 85%) и есть конкретный признак (домен сайта вакансий/рассылки или контакты заявителя в письме), **правило создаётся само** (помечено «створено ШІ») и письма этого отправителя из очереди разбираются
заново обычным путём; если нет — в очереди остаётся подсказка «ШІ пропонує: … (N%)», а для уверенных откликов — данные
из письма для предзаполнения. Правило ШІ видно во вкладке «Правила» (фильтр «Лише створені ШІ») и удаляется как любое
другое; уже разобранные письма при удалении правила **не** переразбираются. Работает только при включённом AI и функции
«ШІ: сортування пошти»; подробности, промпт и лимиты — [ai.md](ai.md).

> **Важно:** форматы писем сайтов вакансий **неизвестны** — разбор написан «терпимым» по типичной структуре уведомления
> об отклике и проверен только на выдуманных примерах. Его нужно **откалибровать на реальных письмах** (см. ниже).

## Как пользоваться
Суперадмин: Адміністрування → **Пошта**.
- Вверху — ящик (подключён / нужно переподключить → ссылка на «Інтеграції»), последняя синхронизация, сколько обработано
  за сутки, сколько незнакомых отправителей. **«Синхронізувати зараз»** — запуск вручную (обычно не нужен: cron каждые
  30 минут).
- **Невідомі відправники**: адрес, пример темы, сколько писем, подсказка (для известных доменов сайтов вакансий —
  «Сайт вакансій» + разбор; для `noreply/newsletter/...` — «Розсилка»). Выбрать тип (и разбор для сайта вакансий),
  галочка «Для всього домену» → «Застосувати»: создаётся правило, отправитель (и весь домен) уходит из очереди. Новые
  письма от него дальше обрабатываются по правилу; уже пропущенные письма не перечитываются.
- **Правила**: `hr@site.ua` — один адрес, `@site.ua` — домен и его поддомены (`@work.ua` покрывает `notify.work.ua`).
  Созданное доменное правило вычищает из очереди адреса этого домена (`UnknownSenderRepository::deleteDomain`): шаблон
  LIKE строится через `Core\Support\Database\Like::escape` c `escape '!'`, поэтому `_` и `%` в домене — обычные символы,
  а не подстановочные (правило `@a_b.example.test` не трогает `axb.example.test`; тест —
  `MailAdminApiTest::test_domain_sweep_treats_like_wildcards_literally`).
  Точный адрес важнее домена, более длинный домен — важнее короткого. Тип, разбор, счётчик срабатываний, удаление.
- **Журнал**: последние 50 писем — когда, от кого, тема, результат (ссылка на кандидата или «Вхідні»); новые сверху, заголовки сортируют и фильтруют (дата — диапазон, отправитель и тема — текст, результат — выбор), состояние в адресе ([guides/tables.md](../guides/tables.md)). Открытая вкладка тоже в адресе (`?tab=unknown|rules|log`); ссылка без `tab`, но с параметрами журнала (`?sort=sender`, `?outcome=…`), открывает «Журнал». Открытый фильтр колонки объявляет число показанных строк — «Знайдено: N» (`appTableSortCount` = `rows().length`, с 2026-10-03).

Рекрутер: задача «Зателефонувати новому кандидату (протягом години)» появляется на главной и в карточке; просроченная
(прошёл час) — сверху списка и с отметкой.

### Калибровка разбора (шаг владельца)
1. Подключить Gmail, создать правила для доменов сайтов вакансий (или принять подсказки из очереди).
2. После первых синхронизаций открыть «Журнал»: результат «Не розпізнано» или кандидаты с пустым ФИО/вакансией в
   «Вхідних» — сигнал поправить разбор.
3. Взять 2–3 реальных письма каждого сайта, **обезличить** (заменить имена, телефоны, e-mail на выдуманные) и по ним
   поправить шаблоны в `backend/app/Modules/MailAgent/Parsers/*Parser.php` (+ добавить обезличенные примеры в
   `tests/Unit/MailAgent/MailParsersTest`). Реальные письма в репозиторий **не коммитить** — он публичный.

## Как устроено

**Ошибки бизнес-правил** (DRY, 2026-10-08): `Exceptions/MailAgentException` наследует `Core\Exceptions\BusinessRuleException` — общий конструктор (код, HTTP-статус, `extra`) и `render()` в JSON `{message, code, ...extra}`; модуль объявляет только именованные коды, ответ API прежний.

- Фронт (2026-10-08): уведомления страницы (`mail.page.ts`, `toast`) идут через общий `NotifyService` (`core/ui/notify.service.ts`) на 3 с, а не через свой `MatSnackBar` + `TranslocoService`.
- Счётчик в меню ([shell.md](shell.md), `GET /api/nav/badges`, [core.md](core.md)): `Services/MailNavBadges` — ключ `mail_unknown` («Пошта», только суперадмин): неизвестные отправители в очереди; не больше 50 — столько же показывает список на странице.
### Таблицы (миграция `Database/Migrations/2026_09_29_110001_create_mail_agent_tables.php`)
| Таблица | Колонки | Заметки |
|---|---|---|
| `sender_rules` | `pattern (unique), kind, parser?, created_by?, source (manual\|ai), ai_confidence?, prompt_version?, ai_request_id?, hits, last_seen_at?` (ШІ-поля — миграция `2026_10_08_100006`) | `kind`: `job_board \| candidate \| colleague \| newsletter \| ignore`; `parser` (только `job_board`, по умолчанию `generic`): `work_ua \| robota_ua \| djinni \| generic` |
| `unknown_senders` | `email (unique), sample_subject, first_seen_at, last_seen_at, count, suggested_kind?, suggested_parser?, ai_status?, ai_kind?, ai_parser?, ai_confidence?, ai_extracted? (json), ai_request_id?` (ШІ-поля — `2026_10_08_100004`) | только адрес и первая тема, **без текста** |
| `mail_messages` | `gmail_id (unique), received_at, sender, subject, kind, parser, outcome, error, candidate_id?, touchpoint_id?` | журнал и идемпотентность; **без текста** |
| `mail_sync_runs` | `trigger (manual\|cron), user_id?, started_at, finished_at, cursor_ms, counts, error` | курсор — максимальный `internalDate` (мс) обработанных писем |

### Синхронизация (`Services/MailSyncService`)
1. Gmail не подключён → `google_gmail_not_connected` (422 для ручного запуска; cron: `{skipped: not_connected}`).
2. `users.messages.list` с `q = "newer_than:2d -in:chats"` и, после первого успешного запуска,
   ` after:<курсор − 60 с>` (перекрытие на задержку доставки; дубли отсекаются по id); страницы по 50, не больше 100 писем
   за запуск, обработка **от старых к новым**, бюджет 40 с (функция Vercel живёт 60 с).
3. id уже есть в `mail_messages` → `duplicates`. Иначе `messages.get(format=full)` → `MailMessageProcessor` → строка в
   `mail_messages`.
4. Курсор сдвигается, только если обработаны **все** найденные письма (иначе следующий запуск продолжит).
   `reconnect_required` / 401 прерывают запуск (ошибка пишется в `mail_sync_runs.error`); ошибка отдельного письма
   (`errors`) — письмо не записывается и будет взято снова.
5. Фоновый запуск действует от имени суперадмина, подключившего Gmail (`integrations.settings.connected_by`, если он
   активен) — он станет владельцем/создателем новых кандидатов.

Запуск: `Services/MailSyncJob` (`ScheduledJob` `mail.sync`) → `POST /api/ops/jobs/run` (cron каждые 30 мин,
[core.md](core.md)); вручную — `POST /api/mail/sync`.

### Обработка письма (`Services/MailMessageProcessor`)
| Условие | Итог (`outcome`) |
|---|---|
| метка `SENT` (наше исходящее) или нет адреса отправителя | `skipped` |
| правило `ignore` / `newsletter` / `colleague` | `skipped` (счётчик правила растёт) |
| правило `candidate`, или правила нет, но адрес = e-mail кандидата | касание: `TouchpointIngestor` (канал `email`, входящее, `external_id` = id Gmail, `integration_key = google_gmail`, `via_product = false`) → `touchpoint` (или `inbox`, если такого кандидата нет) |
| правило `job_board` | разбор → см. ниже |
| ничего не подошло | `unknown_senders` (адрес + тема + подсказка правил) → `unknown`; для отправителя без ответа ШІ — запрос ШІ без ожидания (`ai_status = pending`) |

**Отклик (`job_board`):** парсер правила → `DTO/IncomingApplication {fullName, phone, email, vacancyTitle, vacancyRef,
cvUrl}`; нет ни телефона, ни e-mail → `parse_failed`.
`cvUrl` показывается рекрутеру кнопкой «CV» в карточке кандидата, а входящее письмо подконтрольно отправителю,
поэтому ссылка сохраняется **только если её хост** — сайт вакансий, который мы разбираем (`AbstractMailParser::CV_HOSTS`:
`work.ua`, `robota.ua`, `rabota.ua`, `djinni.co`, `dou.ua`, `linkedin.com` и их поддомены). Сравнивается именно хост
(`parse_url`), а не подстрока URL, и отбрасываются ссылки с логином/паролем: `https://djinni.co@evil.test/cv/1`,
`https://work.ua.evil.test/resume/1`, `https://evil.test/download?from=djinni.co` дают `cv_url = null`. Проверка —
`tests/Unit/MailAgent/MailParsersTest::test_cv_url_is_kept_only_for_known_job_boards`. Вакансия ищется по названию: открытая вакансия с **точно таким же
названием без учёта регистра**, ровно одна (`VacancyRepository::findOpenByTitle`).
- Нашлась → `CandidateService::createOrMatch()` (совпадение по телефону/e-mail/Telegram — тот же кандидат; иначе новый,
  источник по парсеру: `work_ua | robota_ua | djinni | other`, способ добавления `added_via = mail`, канал привлечения — по
  UTM или источнику, [acquisition-channels.md](acquisition-channels.md)) → заявка на первом этапе с датой письма (если ещё нет) →
  касание e-mail (тема + текст, до 5000 символов; в `meta`: `subject, from, full_name, vacancy_title, cv_url, parser`, а также `gmail_thread` и `message_id` —
  чтобы ответ из карточки ушёл в ту же ветку Gmail; ветка **не** используется для поиска кандидата: сайты вакансий кладут
  разных кандидатов в одну ветку) →
  если заявка новая — задача `Scripts\Services\TaskService::scheduleNewApplicantCall()` рекрутеру вакансии →
  `application`.
- Не нашлась → кандидат **не создаётся**: касание с разобранными полями в `meta` уходит во «Вхідні» (или в карточку, если
  контакт уже есть у кандидата) → `inbox` / `touchpoint`. Рекрутер создаёт кандидата из «Вхідних» обычным способом.

Идемпотентность — дважды: `mail_messages.gmail_id` и `touchpoints.unique(channel, external_id)`; задача — один раз на
заявку (`tasks.unique(application_id, rule_key = "mail:new_applicant")`).

### Парсеры (`Parsers/*`, контракт `Contracts/MailParser`, тег `MailAgentServiceProvider::PARSERS_TAG`)
`AbstractMailParser` (общие правила): строки-«метки» uk/ru/en (`Ім'я:`, `ПІБ:`, `Имя:`, `Name:`, `Кандидат:` / `Телефон:`,
`Тел.:`, `Phone:` / `E-mail:`, `Пошта:`, `Почта:` / `Вакансія:`, `Position:`); тема письма («… на вакансію «X»»,
«X відгукнувся на вакансію Y», «… from Name»); затем запасные правила — первый номер из 10–13 цифр, первый e-mail не
с домена отправителя/сайта вакансий и не `noreply/support/info`, строка из 2–3 слов с большой буквы как ФИО, ссылка со
словами `cv/resume/rezume/candidate/file…` как резюме. `WorkUaParser`, `RobotaUaParser`, `DjinniParser` добавляют к ним
**предполагаемые** шаблоны темы (не подтверждены), `GenericParser` — только общие. Новый парсер = класс + строка в
провайдере + значение в `Enums/ParserKey`.

### Классификация (`Contracts/MailClassifier`)
`Services/RulesMailClassifier` (привязан по умолчанию) — правила из `sender_rules` (загружаются один раз за запуск).
ШІ ничего не решает при разборе письма. `Services/AiMailClassifier::suggest()` вызывается процессором для отправителя,
попавшего в очередь без ответа ШІ (`unknown_senders.ai_status IS NULL`), один раз, **без ожидания** (промпт
`Ai/MailClassificationPrompt`, `mail_classify.v4`: адрес, тема ≤ 300, тело ≤ 1500 без цитат и подписи —
`Support/MailBodyCleaner`). Ответ применяет `Ai/MailClassificationAiHandler` (обычно в задаче `ai.poll`):
- `conf ≥ 0.85` (`AUTO_APPLY_CONFIDENCE`) **и** конкретный признак (подсказка по домену совпала с типом или извлечены контакты
  заявителя — `MailClassificationPrompt::autoApplicable`) → `MailAgentService::createAiRule()` — правило на точный адрес, `source = ai`,
  `created_by = null`, `ai_confidence`, `prompt_version`, `ai_request_id`; отправитель уходит из очереди; затем
  `Services/MailReprocessService::reprocessSender()` берёт из журнала письма этого адреса с `outcome = unknown` (до 20),
  заново читает их из Gmail и прогоняет через `MailMessageProcessor`; строка журнала меняется, только пока она `unknown`
  (идемпотентно; касания и так уникальны по id Gmail). Gmail не подключён → письма остаются `unknown`. Если правило на
  адрес уже есть (решил человек) — ШІ его не трогает, остаётся подсказка.
- ниже → `unknown_senders.ai_*`: `ai_status (pending|done|failed), ai_kind, ai_parser, ai_confidence, ai_extracted
  (json {full_name, phone, email, vacancy_title} — только для candidate/job_board при conf ≥ 0.7), ai_request_id`.
Задача `mail.sync` после синхронизации классифицирует до 5 отправителей из очереди, которых ШІ ещё не видел (например,
пришедших при выключенном AI): последнее их письмо берётся из Gmail по id из журнала (`AiMailClassifier::classifyQueued`).
Всё в пределах дневных лимитов AI; при отказе (лимит, выключено) запросы просто не отправляются.
Письмо без темы и тела в ШІ не отправляется (`ai_status = skipped`). Подсказки правил в очереди — `Support/SenderSuggester` (домены сайтов вакансий, `noreply/newsletter/…`).

### Эндпоинты (`/api/mail`, суперадмин: `auth:sanctum` + активный + `can:manage-integrations`)
| Метод и путь | Тело → ответ |
|---|---|
| `GET /status` | `{data: {connection (без токенов), last_sync: {trigger, started_at, finished_at, counts, error}\|null, counts: {rules, unknown, processed_24h}}}` |
| `POST /sync` | `{data: {listed, processed, duplicates, errors, tasks, application, touchpoint, inbox, skipped, unknown, parse_failed}}`; не подключён → 422, `reconnect_required` → 409 |
| `GET /messages` | последние 50 из журнала (без текста) |
| `GET /rules?source=manual\|ai`, `POST /rules {pattern, kind, parser?}`, `PATCH /rules/{id}`, `DELETE /rules/{id}` | дубль шаблона → 409 `duplicate_rule`; шаблон — `адрес` или `@домен` (приводится к нижнему регистру); у правила есть `source`, `ai_confidence`, `prompt_version` |
| `GET /unknown-senders` | до 50, частые сверху; `ai: {status, kind, parser, confidence, extracted} \| null` |
| `POST /unknown-senders/{id}/assign {kind, parser?, scope: email\|domain}` | 201, правило (существующее с тем же шаблоном обновляется); из очереди уходят адрес и, для домена, все его адреса |
| `DELETE /unknown-senders/{id}` | убрать из очереди без правила |

### Слои и связи
`Http/Controllers/MailAgentController` → `Http/Requests/*` → `Services/MailAgentService` (статус, правила, очередь),
`MailSyncService`, `MailMessageProcessor` → `Contracts/*Repository` (`Repositories/Eloquent*`). Связи: GoogleWorkspace —
`GmailClient`, `GoogleConnectionStore` ([google-workspace.md](google-workspace.md)); Recruiting — `CandidateService::
createOrMatch`, `VacancyRepository::findOpenByTitle`, `TouchpointIngestor` ([recruiting.md](recruiting.md)); Scripts —
`TaskService::scheduleNewApplicantCall` ([scripts.md](scripts.md)); Ai — `AiService`, обработчик и шаблон промпта по тегам
([ai.md](ai.md)); Core — `ScheduledJob`;
Auth — `UserRepository::find` (фоновый actor).

### Фронтенд (`frontend/src/app/features/mail-agent`)
`mail.model.ts`, `mail.service.ts` (`mailErrorKey`), `mail.store.ts` (состояние страницы на signals, удаление правила и
«прибрать» — оптимистично с откатом), `mail.page.*` — `/admin/mail` (`roleGuard('superadmin')`): в очереди — подсказка ШІ
(тип выставляется по ней), у правил — пометка «створено ШІ, N%» и флажок «Лише створені ШІ». Строки — `mail.*`.

### Персональные данные
`Privacy\MailPersonalData`: при удалении данных кандидата в `mail_messages` стираются отправитель и тема (строка остаётся
для идемпотентности). Сами письма лежат в Gmail компании — это не наше хранилище. Подробно — [privacy.md](privacy.md).

**Вид (рестайл C «Маршрут», 2026-10-02).** Плитки статуса — карточка с линией и «шпалой» 4px сверху (подключено — зелёная, проблема — янтарная, иначе нейтральная); предупреждения — AA-цвет `--app-warn-text`; заголовок страницы — `headline-small`; шапка журнала — подпись `label-medium`. Тест вида — `features/mail-agent/mail.restyle.spec.ts` (контракт стилей: только токены темы, без hex, линии 1.5px, без «бледности» через opacity).

### Общие хелперы Core (2026-10-02)
- текущий пользователь в контроллерах — общий трейт `Core\Http\Concerns\ResolvesActor` вместо приватной копии `actor()` (`MailAgentController`).

Поведение API не менялось; подробности — [core.md](core.md), раздел «Общие хелперы модулей».

### Общие примитивы фронта
Общий код фронта лежит в `frontend/src/app/core` ([core.md](core.md)); фича его только вызывает.
- Ошибки API → i18n-ключ: `mailErrorKey` — обёртка над общим `apiErrorKey` (`core/api/api-error.ts`) со своими кодами, списком статусов и запасным ключом; набор ключей и тексты прежние.
- HTTP-сервис фичи снимает обёртку ответа `{ data }` общим оператором `unwrapData()` (`core/api/unwrap-data.ts`, тип `DataEnvelope<T>` из `core/api/api.model.ts`) вместо своего `map((r) => r.data)`; параметры запроса без пустых значений — `toParams` из `core/api/http-params.ts`, страница списка — `Paged<T>` оттуда же. Контракт API не менялся.

### Зависимости через контракты (2026-10-08)
- `MailMessageProcessor` ставит задачу «перезвонить новому кандидату» через контракт Scripts `TaskScheduler::scheduleNewApplicantCall()`.
- `AiMailClassifier` зовёт ИИ через контракт Ai `AiGateway`. Тест — `tests/Unit/MailAgent/MailAiGatewayTest.php` (ИИ выключен → ничего не отправляется).
- `MailAgentService` и `MailSyncService` узнают состояние Gmail и того, кто его подключил, через контракт GoogleWorkspace `GoogleConnections`.
- `MailMessageProcessor` создаёт или находит кандидата через контракт Recruiting `CandidateIntake::createOrMatch()`.

## Как проверить
Бэкенд (Gmail подменён `Http::fake`, письма **выдуманы**, `tests/Support/MailFixtures`):
- `tests/Feature/MailAgent/MailSyncTest` — все виды писем за один запуск (отклик → кандидат + заявка + касание + задача со
  сроком +1 ч рекрутеру вакансии; письмо кандидата → касание; неизвестная вакансия → «Вхідні» с разобранными полями;
  рассылка и исходящее → пропуск; незнакомый → очередь без текста), повторный запуск ничего не дублирует и добавляет
  `after:` в запрос; идемпотентность по касанию даже без журнала; задача «новый отклик» — первая в списке задач и
  просрочена; доступ и «не подключён»; cron через `/api/ops/jobs/run` от имени подключившего; `invalid_grant` → 409,
  ошибка запуска, предупреждение на главной; включённый AI без ключа — ни одного запроса кроме Gmail; в логах нет токенов, текста
  письма и контактов.
- `MailAdminApiTest` — доступ, CRUD правил (нормализация, дубль 409, валидация, parser только у `job_board`), очередь
  (назначение на домен убирает поддомены), «прибрать», статус, журнал без текста.
- Unit: `tests/Unit/MailAgent/MailParsersTest` (4 выдуманных формата + HTML-письмо + адреса сайтов не берутся как
  контакт + без контакта → null), `ClassificationTest` (приоритет правил, шаблоны, подсказки).
- `tests/Feature/MailAgent/MailAiTest` — подсказка ниже порога в очереди (с данными из письма, без цитаты, один запрос на
  отправителя), `conf ≥ 0.85` → правило `source = ai` + переразбор писем очереди один раз, фильтр `?source=ai`, удаление
  правила не трогает разобранное, ручное правило побеждает, `mail.sync` классифицирует отправителей без ответа ШІ,
  выключенная функция — без запросов; в логах нет текста письма и ключа.

Фронт: `mail.spec.ts`. Вручную (prod, после подключения Gmail): Пошта → «Синхронізувати зараз» → журнал; отправить на ящик
письмо с выдуманным откликом («Ім'я: …», «Телефон: …») от адреса, для которого создано правило «Сайт вакансій», и с темой
«Новий відгук на вакансію «<название открытой вакансии>»» → кандидат, заявка и задача.

## Доступ к модулю

Ключ модуля `mail-agent`. Суперадмин может выключить модуль для всей компании или скрыть его от части ролей на странице «Адміністрування → Модулі». По умолчанию: включён, роли — только суперадмин. Выключенный модуль отвечает 403 `module_disabled`, его фоновые задачи пропускаются, данные не удаляются. Подробнее — [modules-access.md](modules-access.md).
MySQL 8.4 only (ADR 0010, 2026-10-08): `gmail_id` uses `utf8mb4_bin` unconditionally to preserve source message identity. Test: `tests/Feature/MailAgent/GmailIdMysqlSchemaTest` (binary collation, unique index, `18aB` and `18ab` are two messages).
