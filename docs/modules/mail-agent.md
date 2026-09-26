# Модуль MailAgent — почтовый агент (разбор Gmail)

## Что это и зачем
Отклики с сайтов вакансий (work.ua, robota.ua, Djinni, формы сайта) приходят на почту, и рекрутер переносит их в систему
руками. Почтовый агент делает это сам: каждые 30 минут читает подключённый ящик Gmail и
- **отклик с сайта вакансий** → находит или создаёт кандидата (без дублей), ставит его на вакансию и создаёт рекрутеру
  вакансии задачу **«Зателефонувати новому кандидату» со сроком 1 час** от получения письма;
- **письмо кандидата** (адрес совпал с e-mail кандидата) → касание «E-mail» в его карточке;
- **рассылки, коллеги, «игнорировать»** → пропускаются;
- **незнакомый отправитель** → попадает в очередь «Невідомі відправники», где суперадмин одним кликом говорит, кто это
  (и для одного адреса, и сразу для всего домена). Из таких писем сохраняется **только адрес и тема**, текст — нет.

Решение «что это за письмо» принимается **по правилам отправителей, без AI**. Место для AI-классификатора подготовлено,
но он не вызывает провайдеров, пока владелец не утвердил модели и промпты (общий выключатель AI).

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
  Точный адрес важнее домена, более длинный домен — важнее короткого. Тип, разбор, счётчик срабатываний, удаление.
- **Журнал**: последние 50 писем — когда, от кого, тема, результат (ссылка на кандидата или «Вхідні»).

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
### Таблицы (миграция `Database/Migrations/2026_09_29_110001_create_mail_agent_tables.php`)
| Таблица | Колонки | Заметки |
|---|---|---|
| `sender_rules` | `pattern (unique), kind, parser?, created_by?, hits, last_seen_at?` | `kind`: `job_board \| candidate \| colleague \| newsletter \| ignore`; `parser` (только `job_board`, по умолчанию `generic`): `work_ua \| robota_ua \| djinni \| generic` |
| `unknown_senders` | `email (unique), sample_subject, first_seen_at, last_seen_at, count, suggested_kind?, suggested_parser?` | только адрес и первая тема, **без текста** |
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
| ничего не подошло (и AI не решил) | `unknown_senders` (адрес + тема + подсказка) → `unknown` |

**Отклик (`job_board`):** парсер правила → `DTO/IncomingApplication {fullName, phone, email, vacancyTitle, vacancyRef,
cvUrl}`; нет ни телефона, ни e-mail → `parse_failed`. Вакансия ищется по названию: открытая вакансия с **точно таким же
названием без учёта регистра**, ровно одна (`VacancyRepository::findOpenByTitle`).
- Нашлась → `CandidateService::createOrMatch()` (совпадение по телефону/e-mail/Telegram — тот же кандидат; иначе новый,
  источник по парсеру: `work_ua | robota_ua | djinni | other`, способ добавления `added_via = mail`, канал привлечения — по
  UTM или источнику, [acquisition-channels.md](acquisition-channels.md)) → заявка на первом этапе с датой письма (если ещё нет) →
  касание e-mail (тема + текст, до 5000 символов; в `meta`: `subject, from, full_name, vacancy_title, cv_url, parser`) →
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
`Services/AiMailClassifier` спрашивается только для неизвестных писем и только при включённом `AiPolicy`; **провайдера
не вызывает никогда**: выключен → отказ, включён → лог `mail.ai_classifier_not_configured` и отказ. Подсказки в очереди —
`Support/SenderSuggester` (правила: домены сайтов вакансий, `noreply/newsletter/…`).

### Эндпоинты (`/api/mail`, суперадмин: `auth:sanctum` + активный + `can:manage-integrations`)
| Метод и путь | Тело → ответ |
|---|---|
| `GET /status` | `{data: {connection (без токенов), last_sync: {trigger, started_at, finished_at, counts, error}\|null, counts: {rules, unknown, processed_24h}}}` |
| `POST /sync` | `{data: {listed, processed, duplicates, errors, tasks, application, touchpoint, inbox, skipped, unknown, parse_failed}}`; не подключён → 422, `reconnect_required` → 409 |
| `GET /messages` | последние 50 из журнала (без текста) |
| `GET /rules`, `POST /rules {pattern, kind, parser?}`, `PATCH /rules/{id}`, `DELETE /rules/{id}` | дубль шаблона → 409 `duplicate_rule`; шаблон — `адрес` или `@домен` (приводится к нижнему регистру) |
| `GET /unknown-senders` | до 50, частые сверху |
| `POST /unknown-senders/{id}/assign {kind, parser?, scope: email\|domain}` | 201, правило (существующее с тем же шаблоном обновляется); из очереди уходят адрес и, для домена, все его адреса |
| `DELETE /unknown-senders/{id}` | убрать из очереди без правила |

### Слои и связи
`Http/Controllers/MailAgentController` → `Http/Requests/*` → `Services/MailAgentService` (статус, правила, очередь),
`MailSyncService`, `MailMessageProcessor` → `Contracts/*Repository` (`Repositories/Eloquent*`). Связи: GoogleWorkspace —
`GmailClient`, `GoogleConnectionStore` ([google-workspace.md](google-workspace.md)); Recruiting — `CandidateService::
createOrMatch`, `VacancyRepository::findOpenByTitle`, `TouchpointIngestor` ([recruiting.md](recruiting.md)); Scripts —
`TaskService::scheduleNewApplicantCall` ([scripts.md](scripts.md)); Integrations — `AiPolicy`; Core — `ScheduledJob`;
Auth — `UserRepository::find` (фоновый actor).

### Фронтенд (`frontend/src/app/features/mail-agent`)
`mail.model.ts`, `mail.service.ts` (`mailErrorKey`), `mail.store.ts` (состояние страницы на signals, удаление правила и
«прибрать» — оптимистично с откатом), `mail.page.*` — `/admin/mail` (`roleGuard('superadmin')`). Строки — `mail.*`.

## Как проверить
Бэкенд (Gmail подменён `Http::fake`, письма **выдуманы**, `tests/Support/MailFixtures`):
- `tests/Feature/MailAgent/MailSyncTest` — все виды писем за один запуск (отклик → кандидат + заявка + касание + задача со
  сроком +1 ч рекрутеру вакансии; письмо кандидата → касание; неизвестная вакансия → «Вхідні» с разобранными полями;
  рассылка и исходящее → пропуск; незнакомый → очередь без текста), повторный запуск ничего не дублирует и добавляет
  `after:` в запрос; идемпотентность по касанию даже без журнала; задача «новый отклик» — первая в списке задач и
  просрочена; доступ и «не подключён»; cron через `/api/ops/jobs/run` от имени подключившего; `invalid_grant` → 409,
  ошибка запуска, предупреждение на главной; включённый AI — ни одного запроса кроме Gmail; в логах нет токенов, текста
  письма и контактов.
- `MailAdminApiTest` — доступ, CRUD правил (нормализация, дубль 409, валидация, parser только у `job_board`), очередь
  (назначение на домен убирает поддомены), «прибрать», статус, журнал без текста.
- Unit: `tests/Unit/MailAgent/MailParsersTest` (4 выдуманных формата + HTML-письмо + адреса сайтов не берутся как
  контакт + без контакта → null), `ClassificationTest` (приоритет правил, шаблоны, подсказки, AI не решает).

Фронт: `mail.spec.ts`. Вручную (prod, после подключения Gmail): Пошта → «Синхронізувати зараз» → журнал; отправить на ящик
письмо с выдуманным откликом («Ім'я: …», «Телефон: …») от адреса, для которого создано правило «Сайт вакансій», и с темой
«Новий відгук на вакансію «<название открытой вакансии>»» → кандидат, заявка и задача.
