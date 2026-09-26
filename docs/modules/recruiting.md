# Модуль Recruiting (вакансии, кандидаты, касания)

## Что это и зачем
Сердце SinHRM. Рекрутер в одной карточке кандидата видит:
- **маршрут** — откуда кандидат пришёл (источник, UTM) → через какие этапы прошёл и сколько времени провёл на каждом → оффер, выход на работу или отказ с причиной;
- **все касания** — звонки, Telegram, WhatsApp, Viber, почта, заметки, встречи. И сделанные из SinHRM, и «перехваченные» снаружи
  (когда подключат интеграции): у каждого касания видно, откуда оно — «з SinHRM» или «зовні».

У кандидата два отдельных факта: **канал привлечения** (откуда пришёл, в т. ч. по UTM-меткам) и **способ добавления**
(вручную, импорт, почта, расширение, Google Sheets) — [acquisition-channels.md](acquisition-channels.md). Заявки на подбор,
из которых после согласования открываются вакансии, — [hiring-requests.md](hiring-requests.md).

Руководитель видит **зависших кандидатов** (никто не связывался 3+ дня) и отчёты: кто из рекрутеров сколько касаний сделал и по каким
каналам, воронку по вакансиям, источники и причины отказов.

Воронки **настраиваемые**: у каждой вакансии своя воронка (набор этапов). По умолчанию — «Основна воронка» из 8 этапов
(классические статусы `new → in_review → interview → offer → hired | rejected`, но подробнее).

## Как пользоваться
Меню слева → раздел «Рекрутинг»: **Кандидати**, **Вакансії**, **Вхідні**, **Звіти**. Кандидата ищут полем поиска над списком
(имя, телефон, e-mail). Палитра быстрого перехода (Ctrl/⌘+K) и горячие клавиши (j/k, «/», Ctrl/⌘+Enter) убраны по решению
владельца (2026-09-26): всё делается обычными кликами и полями. Каналы, источники и интеграции везде показаны одинаковыми
иконками Font Awesome в цветах бренда (Telegram, WhatsApp, Viber, LinkedIn, Meta…; у work.ua/robota.ua/Djinni/DOU —
портфель с буквой), даты выбираются из выпадающего календаря ([core.md](core.md)).

- **Вакансії** — список вакансий своих филиалов (по умолчанию открытые), поиск, фильтр статуса, «Нова вакансія» / ✎ (форма: название,
  филиал, должность, статус, описание). Клик по вакансии открывает **доску**: колонка на этап, карточки кандидатов перетаскиваются
  мышью между колонками. Перетащили в «Відмова» — система спросит причину (обязательно) и комментарий. На карточках колонки найма (и в карточке кандидата у
  принятой заявки) — кнопка **«Створити співробітника»**: запись сотрудника из кандидата и вакансии ([people.md](people.md)); повторное
  нажатие откроет того же сотрудника. Если перемещение не прошло —
  карточка вернётся и появится сообщение. Карточки без контакта 3+ дня отмечены значком ⏱ и текстом «N дн. без контакту».
  «Додати кандидата» — новый кандидат сразу на первый этап этой вакансии.
- **Кандидати** — слева список (поиск по имени/телефону/e-mail/@telegram, фильтры статуса и источника), справа карточка.
  Клавиши: `j`/`k` или `↓`/`↑` — следующий/предыдущий кандидат, `/` — поиск. Карточка: контакты (кликабельные), источник, UTM и теги;
  **Маршрут** по каждой вакансии (этапы с датой входа и длительностью, текущий подсвечен) и кнопка «Перемістити»; поле записи касания
  (канал, направление, текст, минуты для звонка/встречи; кнопка **«Шаблон»** вставляет сообщение из
  активного скрипта с подставленными именем, рекрутером и вакансией; для подключённых Telegram/WhatsApp/Viber и e-mail (Gmail с правом отправки;
  у письма есть поле «Тема листа», пусто → «Re: тема последнего письма кандидата») — кнопка **«Надіслати»**; если Gmail
  подключён только на чтение — подсказка «Перепідключіть Google, щоб надсилати листи» и неактивная кнопка; сообщение уходит кандидату через канал, а если канал не подключён — предложение «Записати вручну»,
  [channels.md](channels.md)); **Скринінг ШІ** — по каждой заявке кнопка «ШІ-скринінг»: балл 0–100, вердикт
  (підходить / можливо / не підходить), саммари, сильные стороны, пробелы и вопросы на собеседование с подписью
  **«Оцінка ШІ, рішення за людиною»** (ТЗ 6; только подсказка, заявка никуда не двигается; если ответ не успел — «ШІ ще
  оцінює», результат подтянется при следующем открытии; [ai.md](ai.md)); **Задачі** по кандидату (напоминания, галочка — выполнено);
  **Касання** — лента новых сверху, фильтр-чипы по каналам и «Етапи». У оценённого звонка/сообщения — значок «Скрипт N · правила» (или «· ШІ»),
  клик раскрывает шаги с цитатами и рекомендации ([scripts.md](scripts.md)).
  При создании кандидата с уже известным телефоном/e-mail/Telegram система предложит открыть существующую карточку (если он в
  ваших филиалах) или сообщит, что он есть в другом филиале. Второго кандидата с тем же контактом создать нельзя.
- **Вхідні** — сообщения и звонки, пришедшие снаружи, которые не удалось сопоставить с кандидатом. «Розібрати» → привязать к
  найденному кандидату или создать нового (контакт из сообщения становится его телефоном/e-mail/Telegram).
- **Звіти** — период (по умолчанию последние 30 дней): касания рекрутеров по каналам (из SinHRM / извне), источники (сколько
  кандидатов и сколько из них принято), причины отказов, воронка по вакансиям. Без тяжёлых графиков — таблицы с полосками.

Наблюдатель (viewer) всё видит в пределах своих филиалов, но ничего не меняет (кнопок записи нет, API вернёт 403).

## Как устроено

### Сущности и таблицы (`backend/app/Modules/Recruiting/Database/Migrations`)
| Таблица | Главное | Заметки |
|---|---|---|
| `pipelines` | `name, is_default` | воронка по умолчанию создаётся data-миграцией `…100002_seed_default_pipeline` |
| `pipeline_stages` | `pipeline_id, name, kind (attract\|select\|hire\|closed), position, is_terminal` | `unique(pipeline_id, position)` |
| `reject_reasons` | `name, active` | справочник; не удаляется, выключается; 6 общих причин в data-миграции |
| `vacancies` | `title, branch_id, department_id?, position_id?, recruiter_id, pipeline_id, status (open\|paused\|closed), description, opened_at, closed_at` | воронка вакансии после создания не меняется |
| `candidates` | `full_name, phone (E.164), email (lowercase), telegram_username (lowercase, без @), city_id?, source, channel_id?, added_via?, utm (jsonb), tags (jsonb), owner_id, created_by` | индексы на трёх контактах — ключи дедупликации; `channel_id` / `added_via` — канал привлечения и способ добавления ([acquisition-channels.md](acquisition-channels.md), миграция `2026_10_06_200001`) |
| `acquisition_channels`, `channel_utm_rules`, `acquisition_channel_costs` | справочник каналов привлечения, правила UTM, расходы | [acquisition-channels.md](acquisition-channels.md); data-миграция `…200002_seed_channels_from_sources` |
| `applications` | `candidate_id, vacancy_id, stage_id, status (active\|hired\|rejected), reject_reason_id?, rejected_note, stage_entered_at, last_touch_at, closed_at` | `unique(candidate_id, vacancy_id)` |
| `stage_changes` | `application_id, from_stage_id? (null = создание), to_stage_id, by_user_id?, reason, at` | маршрут кандидата |
| `candidate_profile_urls` | `candidate_id, site (linkedin\|work_ua\|djinni\|dou\|robota_ua), url (unique)` | ссылки на профили из браузерного расширения; нормализованный URL — ещё один ключ дедупликации (миграция `2026_10_01_100001`) |
| `touchpoints` | `candidate_id?, application_id?, branch_id?, stage_change_id?, channel, direction (in\|out), author_id?, occurred_at, body, meta (jsonb: duration_sec, recording_url, contact), external_id, via_product, integration_key` | `unique(channel, external_id)` — дедуп повторной доставки (NULL не конфликтуют) |
| `candidate_screenings` | `application_id, candidate_id, vacancy_id, status (pending\|done\|failed), trigger (manual\|auto), score?, verdict? (fit\|maybe\|no), summary?, strengths/gaps/questions (jsonb), prompt_version, ai_request_id?, error?, requested_by?, completed_at` | ШІ-скринінг (миграция `2026_10_08_100005`); вердикт считает сервер по баллу (≥ 70 / ≥ 40) |
| view `unmatched_messages` | `SELECT * FROM touchpoints WHERE candidate_id IS NULL` | для SQL/BI; API читает саму таблицу. ⚠ `SELECT *` фиксирует колонки при создании: изменение `touchpoints` потребует пересоздать view в той же миграции |

Enum-ы: `Enums/StageKind`, `VacancyStatus`, `ApplicationStatus`, `Channel` (`MANUAL` — каналы ручной записи, `isTouch()` = не `system`),
`Direction`, `CandidateSource` (`manual, work_ua, robota_ua, djinni, linkedin, dou, meta_ads, site, referral, telegram, import, inbox, other`), `TimelineItemType`, `ClipperSite` (сайты расширения: допустимые хосты, нормализация URL, соответствие `CandidateSource`), `AcquisitionChannelType`, `AddedVia` (`manual, import, mail, extension, webhook, sheets`). Поле `source` оставлено для совместимости API; аналитика — по каналу.

### Правила
- **Статус заявки = тип этапа.** Терминальный `closed` → `rejected` (нужен `reject_reason_id`, иначе 422 `reject_reason_required`),
  терминальный `hire` → `hired`, любой другой → `active` (возврат из терминального этапа снова открывает заявку и очищает причину).
  Переход разрешён в любой этап **воронки этой вакансии**, кроме текущего (`same_stage`, `stage_not_in_pipeline` — 422).
- **Каждый шаг маршрута** = строка `stage_changes` + «системное» касание (`channel=system`, `stage_change_id`). В ленте карточки
  показывается сам шаг, системное касание не дублируется.
- **Дедупликация кандидата** (`Services/CandidateService`): телефон нормализуется в E.164 (`067 123-45-67` → `+380671234567`),
  e-mail — в нижний регистр, Telegram — без `@`/`t.me/` (`Support/ContactNormalizer`). Проверка **глобальная** (по всем филиалам):
  совпадение по телефону **или** e-mail **или** Telegram → 409 `duplicate_candidate`, и при создании, и при правке.
  - кандидат **виден** вызывающему (`CandidatePolicy::view`) → `{code, existing_id, matched_by}` — интерфейс предлагает открыть карточку;
  - кандидат **в чужом филиале** → `{code: "duplicate_candidate", restricted: true}` **без id и поля** — чтобы нельзя было перебором
    контактов узнавать о кандидатах других филиалов; интерфейс пишет «є в іншій філії, зверніться до адміністратора».
  - «Создать всё равно» (`force_new`) **убрано**: контакты уникальны на уровне БД (частичные уникальные индексы
    `candidates_{phone,email,telegram_username}_unique … WHERE … IS NOT NULL`, миграция `…100003_add_unique_candidate_contacts`).
    Кандидатов без контактов может быть сколько угодно. Дубль не создают — существующего кандидата добавляют на вакансию
    (`POST /api/vacancies/{id}/applications`) или к нему привязывают сообщение из «Вхідних».
  - Гонка двух одновременных созданий: второй `INSERT` падает на индексе (`UniqueConstraintViolationException`), сервис отвечает
    тем же 409 (с тем же правилом раскрытия).
- **Импорт-готовый DTO:** `DTO/CandidateData::fromArray(array)` принимает «грязную» строку (таблица, выгрузка job-сайта):
  обрезает пробелы, чистит UTM/теги, неизвестный источник → `import`.
- **Создать или найти (для машинных источников)** — `CandidateService::createOrMatch(?User $actor, CandidateData, ?Vacancy,
  ?Carbon $at): DTO/CandidateMatch {candidate, created, application?, applicationCreated}`. Используют импорт из Google Sheets и
  почтовый агент ([google-workspace.md](google-workspace.md), [mail-agent.md](mail-agent.md)). Вместо 409 при совпадении
  контакта (глобально) возвращает существующего кандидата; иначе создаёт (источник по умолчанию `import`, `owner_id`/
  `created_by` = actor, может быть `null` у фоновой задачи). Нет ни одного контакта → `RecruitingException` `no_contacts`;
  новый кандидат без ФИО → `full_name_required`. С вакансией — заявка на первом этапе (с датой `$at`), если её ещё нет.
  Гонка на уникальном индексе → повторный поиск и возврат найденного.
- **Вакансия по названию** — `VacancyRepository::findOpenByTitle($title)`: открытая вакансия с тем же названием без учёта
  регистра; ни одной или несколько → `null` (сравнение в PHP: `lower()` в SQLite не понимает кириллицу).
- **Встречи** (канал `meeting`) создаёт модуль GoogleWorkspace из карточки: событие Google Calendar + касание через
  `TouchpointService::log()` ([google-workspace.md](google-workspace.md)). Публичные ключи `meta` касаний
  (`Http/Resources/TouchpointResource::PUBLIC_META`): `duration_sec, recording_url, contact, from_stage_id, to_stage_id`,
  для почты — `subject, from, parser, full_name, vacancy_title, cv_url`, для встреч — `event_id, meet_link, html_link, start,
  end, meeting_type, title`; остальное, что кладёт интеграция, наружу не отдаётся.
- **Зависшие:** `applications.last_touch_at` обновляет слушатель `Listeners/UpdateLastTouch` на событие `Events/TouchpointRecorded`
  (ручная запись, приём от интеграции, привязка из «Вхідних»). `system` не считается; время только двигается вперёд. Касание без
  заявки обновляет все активные заявки кандидата. «Завис» = активная заявка, у которой `coalesce(last_touch_at, created_at)` старше N
  дней (`Services/StalenessService`, по умолчанию 3).

### Доступ (`Services/RecruitingScope` + `Policies/*`)
| Роль | Видит | Может менять |
|---|---|---|
| superadmin, admin | всё | всё; воронки и причины отказов (gate `recruiting-manage`) |
| hr_manager | всё | ничего (403) |
| recruiter | вакансии своих филиалов (`AccessibleBranches`), их заявки и кандидатов + кандидатов, которых он создал/ведёт; «Вхідні» своих филиалов и свои | вакансии/кандидатов/заявки в этих пределах |
| viewer | как recruiter | ничего (403) |
| employee | ничего, даже если ему назначены филиалы | — |
| нанимающий менеджер вакансии (любая роль) | эту вакансию, её доску, заявки и кандидатов | эту вакансию (кроме смены нанимающего менеджера), движение её заявок, интервьюеров её заявок |
| интервьюер заявки (любая роль) | кандидата этой заявки | ничего |
| заблокированный / без филиалов | ничего (403 / пустые списки) | — |

#### Команда найму (контекстные роли)
Простыми словами: руководителю, который нанимает себе человека, не нужна роль рекрутера — его назначают нанимающим
менеджером конкретной вакансии, и он видит только её. Интервьюер видит только того кандидата, которого собеседует.
- Нанимающий менеджер — `vacancies.hiring_manager_id` (FK `users`, `nullOnDelete`). Ставит/снимает только рекрутинговый
  писатель (gate `recruiting-write`) через `PATCH /api/vacancies/{id}` `{hiring_manager_id}`; самому менеджеру поле
  запрещено (422), чтобы он не передал вакансию. В ответе вакансии — `hiring_manager_id`, `hiring_manager {id, name}`.
- Интервьюеры — таблица `application_interviewers (application_id, user_id, created_at)`, PK по паре, каскадное удаление.
  `PUT /api/applications/{id}/interviewers` `{user_ids: int[]}` заменяет список целиком (пустой — снимает всех, до 20,
  только активные пользователи). Право — `ApplicationPolicy::assignInterviewers` (как у `move`).
- Технически: `DTO/Scope` получил `managedVacancyIds` и `interviewApplicationIds` (заполняет `RecruitingScope::for` через
  `Contracts/HiringTeamRepository`); фильтры списков вакансий, кандидатов и «застоявшихся» заявок добавляют их через `OR`.
  `RecruitingScope::canWorkVacancy` = (писатель и вакансия в его филиалах) или нанимающий менеджер. «Вхідні» и отчёты
  по-прежнему режутся только филиалами — контекстные роли их не открывают.
- Где это в интерфейсе: в диалоге вакансии («Редагувати вакансію») поле «Наймаючий менеджер» — выпадающий список людей,
  видно только рекрутинговым писателям. В карточке кандидата под каждой заявкой — «Інтерв'юери»: писатель выбирает
  несколько человек, сохраняется сразу; остальные видят имена. Список людей — `GET /api/recruiting/assignable-users?q=`
  (активные пользователи `{id, name}`, по имени/e-mail, до 50; доступ — писатели и нанимающие менеджеры хотя бы одной
  вакансии, иначе 403; `q` длиннее 100 — 422). Карточка получает `applications[].interviewers [{id, name}]`.
  Фронтенд: `vacancies/vacancy.dialog.ts`, `card/interviewers-panel.ts`, `hiring-team.ts` (`withCurrent` — текущие
  назначенные всегда есть в списке), методы `assignableUsers` / `setInterviewers` в `recruiting.service.ts`.

Политики: `VacancyPolicy` (view/create/update), `CandidatePolicy` (view/create/update), `ApplicationPolicy::move` (по филиалу вакансии),
`TouchpointPolicy::resolve` (разбор «Вхідних»). Запись проверяется в `FormRequest::authorize()`, чтение карточек — `Gate` в контроллере,
списки фильтруются в репозиториях. Создать вакансию в чужом филиале → 403 `vacancy_out_of_scope`.

### Эндпоинты (все под `auth:sanctum` + `EnsureUserIsActive`; гость → 401)
| Метод и путь | Параметры / тело | Ответ |
|---|---|---|
| `GET /api/pipelines` | — | воронки с этапами (`is_reject`, `is_hire`) |
| `POST /api/pipelines` (admin) | `{name, stages:[{name, kind, is_terminal}]}`; первый этап не терминальный, нужен терминальный `closed` | 201 |
| `GET /api/reject-reasons` | `?all=1` — вместе с выключенными | список |
| `POST /api/reject-reasons`, `PATCH /api/reject-reasons/{id}` (admin) | `{name, active}` | 201 / 200 |
| `GET /api/vacancies` | `q, status, branch_id, recruiter_id, perPage (1..200, строка ок), page` | пагинация; `applications_count`, `active_applications_count` |
| `POST /api/vacancies` | `{title, branch_id, department_id?, position_id?, recruiter_id? (по умолч. автор), pipeline_id? (по умолч. default), status?, description?}` | 201 |
| `GET /api/vacancies/{id}`, `PATCH /api/vacancies/{id}` | PATCH частичный, `pipeline_id` запрещён | вакансия со `stages` |
| `GET /api/vacancies/{id}/board` | — | `{vacancy, applications[]}` (с кандидатом, `is_stale`) |
| `GET /api/vacancies/{id}/sources` | — | заявки вакансии по каналу × способу добавления: `[{channel_id, name, added_via, count, share_pct}]` (ТЗ 3) |
| `POST /api/vacancies/{id}/applications` | `{candidate_id}` | 201; повтор → 409 `already_applied` |
| `GET /api/candidates` | `q` (имя/e-mail/@telegram/цифры телефона), `vacancy_id, stage_id, status, source, channel_id, owner_id, perPage, page` | с краткими заявками |
| `POST /api/candidates` | `{full_name, phone?, email?, telegram_username?, city_id?, source?, channel_id?, utm?, tags?, owner_id?, vacancy_id?}` | 201 / 409 дубль (см. «Правила»); канал — явный, иначе по UTM, иначе по источнику; `added_via = manual`; выключенный канал → 422 `channel_inactive` |
| `GET/POST/PATCH /api/acquisition-channels…` | справочник каналов, UTM-правила, расходы, проверка UTM | см. [acquisition-channels.md](acquisition-channels.md) |
| `GET /api/candidates/{id}` | — | карточка + `applications[]` с `route[]` (`stage_name, entered_at, left_at, duration_sec, by, reason`) и `stages` |
| `PATCH /api/candidates/{id}` | частично; `vacancy_id` запрещён; занятый контакт → 409 | 200 / 409 |
| `GET /api/candidates/{id}/timeline` | `channel=call,telegram,stage` (или массив; `stage` = шаги), `perPage, page` | новые сверху: `{type: touchpoint\|stage_change, at, touchpoint\|stage_change}`; у касания `touchpoint.evaluation` — `{id, score, engine, next_step_fixed, script_version_id}` или `null` (оценка по скрипту, см. ниже) |
| `POST /api/candidates/{id}/touchpoints` | `{channel (note\|call\|meeting\|telegram\|whatsapp\|viber\|email), direction?, body (обязателен для note), occurred_at?, duration_sec?, application_id?}` | 201, `via_product=true` |
| `POST /api/applications/{id}/move` | `{stage_id, reason?, reject_reason_id?}` | заявка; ошибки см. «Правила» |
| `GET /api/recruiting/assignable-users` | `?q=` | `{data: [{id, name}]}` — до 50 активных; 403 не писателю и не нанимающему менеджеру |
| `PUT /api/applications/{id}/interviewers` | `{user_ids: int[]}` | заявка с `interviewers [{id, name}]`; 403 без права, 422 неактивный/несуществующий пользователь или нет поля |
| `GET /api/recruiting/stale` | `days` 1..365 (строка `"3"` ок; по умолч. 3) | до 200 заявок, самые старые сверху; `meta.days` |
| `GET /api/inbox` | `perPage, page` | касания без кандидата в пределах доступа |
| `POST /api/inbox/{touchpoint}/link` | `{candidate_id}` | касание; уже привязано → 409 `already_linked` |
| `POST /api/inbox/{touchpoint}/create-candidate` | `{full_name, vacancy_id?}` | 201 кандидат (source `inbox`); дубль → 409 как при создании (тогда — «Привʼязати») |
| `GET /api/reports/touches` | `from, to` (`YYYY-MM-DD`, по умолч. 30 дней, не больше года) | строки `author × channel × via_product`, `totals {total, via_product, captured}` |
| `GET /api/reports/funnel` | `from, to, vacancy_id?` | заявки, созданные в периоде, по вакансии × текущему этапу |
| `GET /api/reports/sources` | `from, to` | кандидаты периода по источнику + сколько из них `hired` |
| `GET /api/reports/reject-reasons` | `from, to` | отказы (по `closed_at`) по причинам |
| `POST /api/applications/{id}/hire` | `{hired_at?}` — маршрут модуля People, право как у `move` | сотрудник из принятой заявки: 201 / 200 (уже есть) / 422 `not_hired` ([people.md](people.md)) |

Ошибки бизнес-правил — `Exceptions/RecruitingException` → `{message, code, …}`.

### Браузерное расширение (`ExtensionController`, `Services/ClipperService`, `Services/ExtensionTokenService`)
Эндпоинты `/api/me/extension-token` (сессия) и `/api/clipper/*` (только токен с ability `clipper`, `throttle:clipper` —
30/мин на токен) — [extension.md](extension.md). Импорт одной страницы (`ClipperService::import`):
1. `profile_url` проверяется в `ClipCandidateRequest`: https, хост сайта из `source_site` (`linkedin.com`, `work.ua`,
   `djinni.co`, `dou.ua`, `robota.ua`, с `www.` или без), без логина/порта → иначе 422; нормализуется (`ClipperSite::normalizeUrl`; для Work.ua и Robota.ua срезается языковой префикс).
2. Поиск: сначала `candidate_profile_urls.url`, затем телефон → e-mail → Telegram (глобально, как везде).
3. Найден, но не виден пользователю (чужой филиал) → 409 `duplicate_candidate {restricted: true}`, ссылка не привязывается.
   Найден и виден → 200: ссылка привязывается (если новая), заявка на вакансию создаётся (если её ещё нет).
4. Не найден → 201: кандидат (`source` = сайт, владелец и автор — пользователь токена) + ссылка + заявка в одной транзакции;
   гонка по уникальным индексам → повторный поиск, как совпадение.
5. Заметка (`channel=note`, `meta.source=extension`) «Imported from <Сайт>: <url>» + заголовок, город, текст до 2000 символов —
   для нового кандидата и для найденного, если добавилась ссылка или заявка; повторный клик на той же странице ничего не пишет.
Вакансия не из области видимости → 403 `vacancy_out_of_scope`; роль без права записи (viewer) → 403.

### Оценка касаний по скрипту в ленте (`Contracts/TouchpointEvaluations`)
Recruiting не знает, как оцениваются разговоры: `TouchpointService::timeline()` после выборки страницы запрашивает у
контракта `TouchpointEvaluations::summaries(ids)` краткие оценки касаний этой страницы (один запрос) и кладёт их в
`TimelineEntry::$evaluation`. По умолчанию привязан `Support/NullTouchpointEvaluations` (оценок нет); модуль Scripts
подменяет его своей реализацией ([scripts.md](scripts.md)). Сама оценка запускается модулем Scripts по событию
`TouchpointRecorded`.

### ШІ-скринінг (ТЗ 6: `Services/ScreeningService`, `Ai/*`, `Http/Controllers/ScreeningController`)
- `POST /api/applications/{id}/screening` — право `CandidatePolicy::update` (FormRequest `ScreenApplicationRequest`), лимит
  20/мин. Незавершённый скрининг заявки возвращается повторно (без нового запроса и расходов). Ждёт ответ ≤ 40 с:
  201 `done` / 202 `pending` (его завершит `ai.poll` или следующий `GET`). Отказ AI → `{code}`: `ai_disabled`,
  `ai_not_configured`, `ai_purpose_disabled` (422, строка не создаётся), `ai_budget_exceeded` (429, строка `failed`).
- `GET /api/candidates/{id}/screenings` — право просмотра кандидата; последняя оценка по каждой заявке; до 3 незавершённых
  опрашиваются у брокера один раз.
- Промпт `Ai/ScreeningPrompt` (`screening.v4`) из `Ai/ScreeningInput`, который собирает `Ai/ScreeningPromptFactory`:
  название/должность/отдел/описание вакансии, город и теги кандидата, 20 последних касаний-материалов (заметки — в т.ч.
  текст резюме из клиппера — и входящие сообщения/расшифровки кандидата) через `PiiRedactor` (без ФИО, телефонов,
  e-mail, ссылок, @ников); нет ни одного материала → модель не вызывается, скрининг `failed` / `insufficient_data`;
  невыполненное обязательное требование (`unmet`) → балл ≤ 69. Авторы заметок и данные сотрудников не отправляются. `Ai/ScreeningAiHandler` пишет ответ
  (`pending → done|failed` один раз).
- **Автоскрининг** (настройка `ai_screening_auto`, по умолчанию выкл.): задача `ai.screen` (cron) отправляет до 5 новых
  активных заявок за 48 ч без скрининга, без ожидания. Ранжирование откликов по баллу на доске/в списке — **не сделано**
  (следующий шаг ТЗ 6).

### Контракт для интеграций (приём касаний)
```php
interface TouchpointIngestor { public function ingest(IncomingMessage $message): Touchpoint; }
// DTO/IncomingMessage: channel, direction, occurredAt, contact (телефон | e-mail | @username | t.me/…),
//                      body?, externalId?, integrationKey?, branchId?, authorId?, viaProduct=false, meta[],
//                      thread? (id разговора в источнике), candidateId? / applicationId? (явная цель — отправка из карточки)
```
Реализация `Services/MatchingTouchpointIngestor`: (1) есть `externalId` и такое касание в этом канале уже есть — вернуть его
(повторная доставка вебхука безопасна; одновременная вставка того же id ловится уникальным индексом и тоже возвращает сохранённое);
(2) кандидат: явный `candidateId`, иначе кандидат, уже привязанный к той же ветке (`meta.thread`) в этом канале
(`TouchpointRepository::candidateIdByThread`), иначе `ContactNormalizer::guess(contact)` → поиск по телефону/e-mail/Telegram;
(3) нашли — привязка к указанной (`applicationId`) или последней активной заявке, событие `TouchpointRecorded` (обновит «зависание»); не нашли — касание остаётся в
«Вхідних» (`candidate_id = null`), `branchId` линии/аккаунта определяет, каким рекрутерам его видно. Его вызывают почтовый агент
([mail-agent.md](mail-agent.md)) и модуль Channels ([channels.md](channels.md)) — вебхуки Telegram/WhatsApp/Viber/телефонии,
демо-события и сообщения, отправленные из карточки. Для каналов есть ещё `TouchpointRepository::latestThreadOf` (куда отвечать),
`latestInbound` (последнее входящее — письмо, на которое отвечает e-mail из карточки) и `lastInboundAt` (окно 24 ч WhatsApp). В `TouchpointResource` добавлены публичные ключи meta `sender_name, call_status, edited, demo`.

### Демо-данные
Логика — сервис `Services/RecruitingDemoData::generate(): DTO/DemoReport` (`skipped`, `counts`, `seconds`). В production бросает
`RuntimeException`; повторный вызов ничего не делает (`skipped = true`, маркер — пользователь `demo-recruiter-1@example.test`);
длительность и счётчики пишутся в лог `recruiting.demo_generated`. На SQLite `generate()` занимает ~0.4 с (тест
`DemoCommandTest` требует < 40 с: сид идёт внутри HTTP-запроса с лимитом функции 60 с).

Вызывают его:
- **seeder** `Database/Seeders/RecruitingDemoSeeder` — из `DatabaseSeeder`, если `APP_ENV != production`. Именно сидер, а не
  `Artisan::call('recruiting:demo')`: preview пересоздаёт БД через `POST /api/ops/migrate?fresh=1` (`migrate:fresh --seed` внутри
  HTTP-запроса), а там консольные команды не зарегистрированы (была ошибка `The command "recruiting:demo" does not exist`);
- **команда** `php artisan recruiting:demo` — тонкая обёртка для локального запуска (в production — ошибка и код 1).

Создаёт, если в БД нет хотя бы двух активных филиалов, 3 демо-филиала/2 города/3 должности; 3 демо-пользователя (admin +
2 recruiter, привязаны к филиалам) и viewer; 5 вакансий; **40 кандидатов** на разных этапах (часть отклонена с причиной, часть
принята) с касаниями **по всем каналам** — и «из SinHRM», и «извне»; ~8 зависших; 6 сообщений в «Вхідних». Имена — сочетания общих
имён/фамилий из списка в коде (Faker — dev-зависимость, на деплое его нет), e-mail на зарезервированном домене `example.test`,
телефоны выдуманные. Всё создаётся через настоящие сервисы.

### Слои
`Http/Controllers/*` (оркестрация) → `Http/Requests/*` (валидация + `authorize()`) → `Services/*` → `Contracts/*Repository`
(`Repositories/Eloquent*`, `QueryReportRepository`). Привязки — `Providers/RecruitingServiceProvider`.

### Фронтенд (`frontend/src/app/features/recruiting`)
| Файл | Что |
|---|---|
| `recruiting.model.ts`, `recruiting.service.ts` | типы API, HTTP-клиент, `recruitingErrorKey`, `duplicateOf` |
| `recruiting.format.ts`, `recruiting.access.ts` | длительности, группировка по этапам, статус этапа, диапазон дат; `canWriteRecruiting` |
| `vacancies/` | список + `VacancyDialog` (`/vacancies`) |
| `board/` | доска CDK drag&drop (`/vacancies/:id`), оптимистичный перенос с откатом, `RejectDialog`; «Створити співробітника» в колонке найма (`features/people/hire.action.ts`) |
| `candidates/` | split view (`/candidates`, `/candidates/:id`), `CandidateDialog` с обработкой дубля; иконки источников — `core/ui/channel-icon.ts` |
| `card/` | карточка: маршрут, перемещение, лента с фильтрами, `TouchComposer` (с кнопкой «Шаблон» — `features/scripts/templates/template-menu.ts` и «Надіслати» через `features/channels/channels.service.ts`), значок оценки у касания (`features/scripts/evaluation/evaluation-badge.ts`), задачи кандидата (`features/scripts/tasks/tasks-widget.ts`), кнопка «Запланувати зустріч» (`features/google-workspace/meeting.dialog.ts`; неактивна, если `GET /api/google/calendar` → `connected: false`), у касаний-встреч — время, ссылка Meet с копированием и ссылка на событие, у писем — ссылка на резюме; `screening-panel.ts` — ШІ-скринінг по заявкам (`RecruitingService.screenings/screen`, коды ошибок — `features/ai/ai.service.ts`) |
| `inbox/` | `/inbox` + `InboxResolveDialog` (привязать / создать) |
| `reports/` | `/reports`, период — `mat-date-range-picker` (в API уходит `YYYY-MM-DD`), таблицы с CSS-полосками, `pivotTouches`, иконки каналов в заголовках |
| `channels/` | `/admin/acquisition-channels` — справочник каналов, правила UTM с проверкой, расходы; `board/vacancy-sources.ts` — блок «Джерела відгуків» на доске; в карточке — чипы канала и «як додано», в форме — «Канал залучення», в списке — фильтр по каналу |
| `features/extension/` | `/settings/extension` — токен расширения ([extension.md](extension.md)); источники `linkedin`, `dou` в `recruiting.model.ts` |

Строки — `recruiting.*` в `public/i18n/{uk,ru,en}.json`. Общие стили страниц (`.page-head`, `.filters`, `.panel`, `.state`)
и токен `--app-warning` — в `styles.scss`.

## Как проверить
Бэкенд: `tests/Feature/Recruiting/*` — вакансии (401/403, филиалы, роли, фильтры, доска, добавление), кандидаты (нормализация,
дубль 409 по трём ключам, скрытие id для чужого филиала, уникальные индексы в БД, поиск, карточка с маршрутом и длительностями, права), перемещения (stage_change + системное
касание, причина отказа, hired, чужая воронка, роли), лента (порядок, фильтр, пагинация, ручное касание и `last_touch_at`),
«Вхідні» (область видимости, привязка, создание, 409), зависшие (`days` строкой, валидация, филиалы), отчёты (суммы, период),
воронки и причины, демо (данные, повтор, отказ в production, `DatabaseSeeder` без зарегистрированных консольных команд, время `generate()`). `tests/Unit/Recruiting/*` — нормализатор контактов,
ingestor (сопоставление, дедуп, «Вхідні»), `CandidateService` (моки: раскрытие дубля, гонка → 409), `ApplicationService`.
`createOrMatch`, `findOpenByTitle` и встречи проверяются тестами модулей-потребителей: `tests/Feature/GoogleWorkspace/{SheetsImportTest,MeetingTest}`,
`tests/Feature/MailAgent/MailSyncTest`.
`tests/Feature/Recruiting/ExtensionApiTest.php` — токен (выдача, ротация, отзыв, срок), изоляция токена от остального API,
импорт (создание с заметкой, совпадение по ссылке/телефону/e-mail, хост ссылки → 422, филиалы, viewer, лимит 30/мин, CORS);
`tests/Unit/Recruiting/ClipperSiteTest.php` — нормализация ссылок.
ШІ-скринінг: `ScreeningApiTest` (результат и подпись, в запросе нет ФИО и контактов, медленный ответ → 202 → готово при
открытии, повторный клик не создаёт второй запрос, права по `CandidatePolicy`, отказы с кодами, автоскрининг выкл./вкл.).
Каналы привлечения: `AcquisitionChannelsApiTest` (права, приоритет UTM, способ добавления, миграция источников, математика отчёта), `ChannelSupportTest`; фронт — `channels/channels.spec.ts`.
Фронт: `features/extension/extension.spec.ts`, `recruiting.service.spec.ts`, `recruiting.format.spec.ts`, `recruiting.stores.spec.ts`.

Вручную на preview (нужна сессия; демо-данные уже в БД):
```bash
curl -i "https://<preview>/api/recruiting/stale?days=3"      # без сессии → 401
```
**Не проверено в этой задаче:** реальные запросы к preview/prod (вход только через Google на prod-домене), запросы на Postgres
локально (тесты гонялись на SQLite; CI — Postgres).

## Доступ к модулю

Ключ модуля `recruiting`. Суперадмин может выключить модуль для всей компании или скрыть его от части ролей на странице «Адміністрування → Модулі». По умолчанию: включён, роли — все роли (как и до появления выключателя). Выключенный модуль отвечает 403 `module_disabled`, его фоновые задачи пропускаются, данные не удаляются. Нанимающий менеджер и интервьюер видят рекрутинг, только если модуль включён и их базовая роль (обычно `employee`) отмечена. Подробнее — [modules-access.md](modules-access.md).
