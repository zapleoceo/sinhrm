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
- **Історія** в карточке кандидата (раскрывающийся раздел внизу, всем, кто видит карточку) — правки кандидата и переводы по воронке всех его откликов из журнала действий; контакты показаны как `***`. `GET /api/candidates/{id}/history` (политика `view`), подробно — [audit.md](audit.md).
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
  Ранжирование использует только отклики, доступные текущей роли: скрытые отклики не меняют балл кандидата, фильтры или порядок списка.
- **Вхідні** — сообщения и звонки, пришедшие снаружи, которые не удалось сопоставить с кандидатом. «Розібрати» → привязать к
  найденному кандидату или создать нового (контакт из сообщения становится его телефоном/e-mail/Telegram).
- **Звіти** — период (по умолчанию последние 30 дней): касания рекрутеров по каналам (из SinHRM / извне), источники (сколько
  кандидатов и сколько из них принято), причины отказов (переключатель «Усі / За етапом / За рекрутером»: причина × этап, на
  котором отказали, или × рекрутер — автор шага отказа; «Етап не визначено», «Рекрутера не призначено», «Інший користувач»
  для автора без роли подбора (шаг отказа ищется только среди заявок, отказанных в периоде и видимых пользователю; индекс
  `stage_changes (application_id, to_stage_id)`; видимость отчёта: руководитель-наниматель — отказы своих вакансий, интервьюер —
  только своя заявка, общий кандидат с заявками чужого филиала не раскрывается — Feature-тест в `ReportsApiTest`); сортировка/фильтры в заголовках, префиксы URL `rej_`, `rjs_`, `rjr_`), воронка по вакансиям. Без тяжёлых графиков — таблицы с полосками;
  у таблиц с 2+ строками — строка «Разом». Воронка — сетка **отдельных карточек**, по одной на вакансию (auto-fill от
  320px, на телефоне одна колонка): заголовок (название, статус-чип, филиал · рекрутер, «Активних: N» — кандидаты на
  этапах attract/select), этапы по порядку воронки с количеством и долей от активных кандидатов вакансии (счётчики текущие, не накопительные,
  поэтому «конверсия к предыдущему этапу» была бы неверной; у найма и отказов доли нет),
  найм — зелёная полоса, отказы — красная, внизу «Відкрита N дн.» (от `opened_at`). Пустая вакансия — «Немає кандидатів».
  Сортировка — по активным кандидатам; при 7+ карточках — поиск по названию вакансии (на клиенте).

Наблюдатель (viewer) всё видит в пределах своих филиалов, но ничего не меняет (кнопок записи нет, API вернёт 403).

## Как устроено
- Счётчик в меню ([shell.md](shell.md), `GET /api/nav/badges`, [core.md](core.md)): `Services/InboxNavBadges` — ключ `inbox`: сообщения во «Вхідні» без кандидата в области видимости пользователя (`InboxService::list(..., 1)->total()`, то же число, что `meta.total` списка).

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
`Direction`, `CandidateSource` (`manual, work_ua, robota_ua, djinni, linkedin, dou, meta_ads, site, referral, telegram, import, inbox, other`), `TimelineItemType`, `ClipperSite` (сайты расширения: допустимые хосты, нормализация URL, соответствие `CandidateSource`), `AcquisitionChannelType`, `AddedVia` (`manual, import, mail, extension, webhook, sheets, career_site` — последнее ставит публичная страница вакансий `/jobs`, `CareerSiteService`). Поле `source` оставлено для совместимости API; аналитика — по каналу.

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
  Фронтенд: `vacancies/vacancy-form.page.ts`, `card/interviewers-panel.ts`, `hiring-team.ts` (`withCurrent` — текущие
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
| `GET /api/reports/funnel` | `from, to, vacancy_id?` | заявки, созданные в периоде, по вакансии × текущему этапу; в строке также `vacancy_status, branch_name, recruiter_name, opened_at` (шапка карточки) |
| `GET /api/reports/sources` | `from, to` | кандидаты периода по источнику + сколько из них `hired` |
| `GET /api/reports/reject-reasons` | `from, to` | отказы (по `closed_at`) по причинам: `rows [{reject_reason_id, name, count}]`, плюс разрезы `by_stage [{reject_reason_id, name, stage_id, stage_name, count}]` и `by_recruiter [{reject_reason_id, name, recruiter_id, recruiter_name, recruiter_state, count}]`, `totals {total}` (каждый разрез в сумме = `total`). Видимость — `ApplicationVisibility` (филиалы + контекстные роли). Этап — `from_stage_id` последнего `stage_change` в текущий этап отказа; нет такого шага → `stage_id: null` («этап не определён»). Рекрутер — автор этого шага (`by_user_id`, кто фактически отказал; самый надёжный источник: исторический факт, а не текущий `vacancies.recruiter_id`/`candidates.owner_id`, которые меняются после отказа). `recruiter_state`: `user` — назван (есть роль подбора superadmin/admin/recruiter), `hidden` — автор без роли подбора (например, нанимающий менеджер): считается, но не раскрывается, `unassigned` — автора нет (системный шаг или пользователь удалён). Порядок стабильный: `count` desc, причина, этап по позиции / имя рекрутера, неизвестные последними, id |
| `POST /api/applications/{id}/hire` | `{hired_at?}` — маршрут модуля People, право как у `move` | сотрудник из принятой заявки: 201 / 200 (уже есть) / 422 `not_hired` ([people.md](people.md)) |

Ошибки бизнес-правил — `Exceptions/RecruitingException` → `{message, code, …}`.

### Браузерное расширение (`ExtensionController`, `Services/ClipperService`, `Services/ExtensionTokenService`)
Эндпоинты `/api/me/extension-token` (сессия) и `/api/clipper/*` (только токен с ability `clipper`, `throttle:clipper` —
30/мин на токен) — [extension.md](extension.md). Выдачу/отзыв делает общий `Auth\Services\PersonalTokens`, путь
токена регистрируется в `Auth\Support\TokenScopes` (`api/clipper/*` → `clipper`) — [auth.md](auth.md). Импорт одной страницы (`ClipperService::import`):
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
- `POST /api/applications/{id}/screening` — право `CandidatePolicy::update` **и видимость самой заявки**
  (FormRequest `ScreenApplicationRequest`), лимит
  20/мин. Незавершённый скрининг заявки возвращается повторно (без нового запроса и расходов). Ждёт ответ ≤ 40 с:
  201 `done` / 202 `pending` (его завершит `ai.poll` или следующий `GET`). Отказ AI → `{code}`: `ai_disabled`,
  `ai_not_configured`, `ai_purpose_disabled` (422, строка не создаётся), `ai_budget_exceeded` (429, строка `failed`).
- `GET /api/candidates/{id}/screenings` — право просмотра кандидата; последняя оценка только по видимым заявкам;
  до 3 видимых незавершённых опрашиваются у брокера один раз.
- Промпт `Ai/ScreeningPrompt` (`screening.v4`) из `Ai/ScreeningInput`, который собирает `Ai/ScreeningPromptFactory`:
  название/должность/отдел/описание вакансии, город и теги кандидата, 20 последних касаний-материалов данной заявки
  или кандидата без привязки к заявке (заметки — в т.ч.
  текст резюме из клиппера — и входящие сообщения/расшифровки кандидата) через `PiiRedactor` (без ФИО, телефонов,
  e-mail, ссылок, @ников); нет ни одного материала → модель не вызывается, скрининг `failed` / `insufficient_data`;
  невыполненное обязательное требование (`unmet`) → балл ≤ 69. Авторы заметок и данные сотрудников не отправляются. `Ai/ScreeningAiHandler` пишет ответ
  (`pending → done|failed` один раз).
- **Автоскрининг** (настройка `ai_screening_auto`, по умолчанию выкл.): задача `ai.screen` (cron) отправляет до 5 новых
  активных заявок за 48 ч без скрининга, без ожидания.
- **Ранжирование (ТЗ 6):** кнопка «Сначала высокий балл ИИ» включает сортировку по убыванию; повторное нажатие
  возвращает обычный порядок. Список: `GET /api/candidates?sort=screening_score` сортирует до пагинации,
  балл `screening_score` — максимум последних завершённых оценок активных заявок кандидата; фильтры вакансии,
  этапа и статуса ограничивают учитываемые заявки (заданный статус заменяет active). Доска: тот же балл заявки
  в каждой общей и личной колонке, без изменения этапов и личной раскладки. Новая pending/failed попытка убирает
  предыдущий балл до успешного завершения. Без оценки — «—», такие кандидаты идут после оценённых, включая балл 0;
  одинаковые баллы сохраняют обычный порядок. Метка «Оценка ИИ, решение за человеком» видна рядом с управлением.
  Сортировка только читает сохранённые результаты: не запускает и не опрашивает AI и не отклоняет кандидатов. Перенос карточки сохраняет балл на доске; фильтры списка не меняют сохранённый результат.

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

`populate(…, $from)` / `extraTouch()` — те же истории (порциями: `$from` — номер первой истории; external id касаний — `demo-fill-c<n>-…`/`demo-fill-t<n>-…`, чтобы не совпасть с превью-сидом и между запросами; кандидат с занятым контактом пропускается (контакты проверяются заранее, без падающего INSERT — он оборвал бы транзакцию Postgres); касание привязано к кандидату явно, `candidateId`) кандидатов с суффиксом имени (пометка « [ТЕСТ]» в конце: «Іваненко Олена [ТЕСТ]»), сроком до 180 дней, шагами воронки в днях и каналами привлечения (`$channelIds` — id канала по коду = значению источника; в этом режиме источники идут миксом `FILL_SOURCES` — сайт с `added_via=career_site`, воронка сужается: большинство остаётся на ранних этапах, каждый 12-й нанят, каждый 4-й отклонён на достигнутом этапе с причиной по весам `REASON_MIX` из всех активных причин; раньше `i ≡ 5 (mod 6)` при 6 причинах всегда давал «Інше»); их вызывает общий демо-заполнитель `Core/Services/Demo/DemoDataService` (`POST /api/ops/demo-fill`, работает и в production, данные помечены ` [ТЕСТ]` в конце имени).

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
| `vacancies/` | список (`/vacancies`) + страница формы вакансии `VacancyFormPage` (`vacancy-form.page.ts`: `/vacancies/create`, `/vacancies/:id/edit`; раньше был диалог, заменён в #88) |
| `board/` | доска CDK drag&drop (`/vacancies/:id`), оптимистичный перенос с откатом, `RejectDialog`; «Створити співробітника» в колонке найма (`features/people/hire.action.ts`) |
| `candidates/` | split view (`/candidates`, `/candidates/:id`), `CandidateDialog` с обработкой дубля; иконки источников — `core/ui/channel-icon.ts` |
| `card/` | карточка: маршрут, перемещение, лента с фильтрами, `TouchComposer` (с кнопкой «Шаблон» — `features/scripts/templates/template-menu.ts` и «Надіслати» через `features/channels/channels.service.ts`), значок оценки у касания (`features/scripts/evaluation/evaluation-badge.ts`), задачи кандидата (`features/scripts/tasks/tasks-widget.ts`), кнопка «Запланувати зустріч» (`features/google-workspace/meeting.dialog.ts`; неактивна, если `GET /api/google/calendar` → `connected: false`), у касаний-встреч — время, ссылка Meet с копированием и ссылка на событие, у писем — ссылка на резюме; `screening-panel.ts` — ШІ-скринінг по заявкам (`RecruitingService.screenings/screen`, коды ошибок — `features/ai/ai.service.ts`) |
| `inbox/` | `/inbox` + `InboxResolveDialog` (привязать / создать) |
| `reports/` | `/reports`, период — `mat-date-range-picker` (в API уходит `YYYY-MM-DD`), таблицы с CSS-полосками, `pivotTouches`, иконки каналов в заголовках |
| `channels/` | `/admin/acquisition-channels` — справочник каналов, правила UTM с проверкой, расходы; `board/vacancy-sources.ts` — блок «Джерела відгуків» на доске; в карточке — чипы канала и «як додано», в форме — «Канал залучення», в списке — фильтр по каналу |
| `card/offer-panel.ts`, `card/offers.service.ts` | оффер заявки в карточке; HTTP — `OffersService` (оффер или `null`, шаблоны, создать, «send» / «decision»), компонент `HttpClient` не держит |
| `careers/careers.ts`, `careers/careers.service.ts` | публичные `/jobs` и `/jobs/:slug` (`JobsPage`, `JobPage`); HTTP — `PublicCareersService` (список, вакансия по slug, отклик multipart), тип `PublicVacancy` |
| `features/extension/` | `/settings/extension` — токен расширения ([extension.md](extension.md)); источники `linkedin`, `dou` в `recruiting.model.ts` |

Строки — `recruiting.*` в `public/i18n/{uk,ru,en}.json`. Общие стили страниц (`.page-head`, `.filters`, `.panel`, `.state`)
и токен `--app-warning` — в `styles.scss`.

**Вид страниц подбора — рестайл C «Маршрут» (2026-10-02, [design-direction.md §4.2](../architecture/design-direction.md)).**
Только стили и классы в шаблонах; логика, подписи, кнопки и поля не менялись (инвентарь ui-parity тот же).
- **Кандидаты** (`/candidates`): список и карточка — белые панели с линией 1.5px; у строки списка «станция» цвета
  типа текущего этапа первой заявки (`.app-station[data-kind]`, выбранный кандидат — залитая станция + синяя полоса
  слева), строки ≥ 44px для касания.
- **Карточка кандидата**: «Маршрут» заявки нарисован линией метро — этапы-станции цвета по типу (`attract` / `select` /
  `hire` / `closed`, закрытый — квадрат), текущий залит и с ореолом; статус заявки — пилюля с маркером-формой
  (активна ○, найм ●, отказ ■); лента касаний — иконки на одной вертикальной линии, время/телефоны моноширинные.
- **Вакансии**: строки 52px, разделители «трек», статус «Активна» — заливка + ● (иначе пунктирное ○), счётчики
  моноширинные; на телефоне название — во всю ширину строки. **Форма вакансии**: карточки-панели, заголовок шрифтом
  Display, пилюля статуса с маркером, редактор описания с кольцом фокуса как у полей, липкая панель действий — мягкая
  тень оверлея (`--app-overlay-shadow`).
- **«Вхідні»**: счётчик — моно-цифры в кольце, иконка канала в круге-станции. **Звіти**: полоски этапов цвета
  `--app-stage-*`, числа моно, статус вакансии — пилюля с формой (открыта ●, пауза ◆, закрыта пунктир ○).
- Цвета только токенами (`--app-*`, `--mat-sys-*`), без hex и `rgb()`-теней — это проверяет `recruiting.restyle.spec.ts`.
- Текст предупреждений и ошибок — токены `--app-warn-text` / `--app-bad-text` (AA 4.5:1 на карточке в обеих темах,
  `frontend/scripts/contrast-check.mjs`), а не «сырые» `--app-warning` / `--app-danger`: значок «давно без движения» в
  списке кандидатов, ошибка диалога кандидата, ошибка и рамка плашки «черновик восстановлен» формы вакансии.

### Права кандидата на его данные (Закон №2297-VI)
Простыми словами: кандидат может попросить показать всё, что мы о нём храним, и удалить это.
- **Показать** — суперадмин или админ открывает карточку кандидата → «Персональні дані» → «Експорт даних». Есть два файла:
  JSON (для машин) и HTML (для чтения человеком). Внутри — профиль, ссылки на профили, отклики с историей этапов, все
  сообщения и звонки, заметки, встречи, ШІ-скринінг, задачи рекрутера, журнал писем.
- **Удалить** — там же «Видалити персональні дані»: нужна причина и подтверждение, действие необратимо. Имя меняется на
  «Видалений кандидат #id», контакты, тексты сообщений и заметок, ссылки, ШІ-оценки стираются. Остаётся обезличенный след
  для отчётов: вакансия, этапы, даты, канал — поэтому воронка и отчёты не «проседают».
- **Срок хранения** — в «Адміністрування → Персональні дані» можно включить автоматическое обезличивание отклонённых
  кандидатов через N месяцев (по умолчанию выключено).
- Нанятого и ещё работающего кандидата удалить нельзя: сначала увольнение, потом удаление данных сотрудника.
Код: `Privacy\CandidatePersonalData` (провайдер Recruiting), колонка `candidates.anonymized_at` (есть в `CandidateResource`).
Всё остальное — [privacy.md](privacy.md).

### Массовые действия над кандидатами (2026-09-27)
Список кандидатов: чекбоксы (у кого есть право записи) → «Масові дії»: переместить на этап / отказать с причиной (в пределах
одной вакансии), добавить тег, назначить рекрутера (`owner_id`). `POST /api/candidates/bulk {action: move|reject|tag|assign, ids[≤200],
vacancy_id?, stage_id?, reject_reason_id?, reason?, tag?, owner_id?}` → `{data: [{id, ok, error}]}`. Каждый элемент проходит ту же
политику (`move` для заявки, `update` для кандидата) и тот же сервис (`ApplicationService::move`, `CandidateService::update`), что и
одиночное действие; ошибки по элементу: `not_found`, `no_application`, `forbidden`, коды `RecruitingException` (`same_stage`, …).

**Вид доски (2026-10-02, рестайл C «Маршрут»).** Заголовки колонок — станции на одной линии, которая идёт через всю
доску: кольцо-станция (`.app-station` из `styles.scss`) и отрезок до следующей станции в цвете **типа** этапа
(`data-kind`: attract / select / hire / closed → токены `--app-stage-*`; закрытый этап — квадрат, а не только красный), число
карточек — пилюля на линии. Свои колонки личной доски — пунктирная «запасная ветка» в цвете колонки. На карточке нет
мини-прогресса из точек: этап — это колонка; стоп-сигнал «без контакту» — полоса слева + иконка + текст; телефон моноширинный.
Подсказка прокрутки — затухание и «›» у правого края, пока справа есть колонки; чистый CSS (scroll-driven animation по
прокрутке `.board`), без скрипта; если ничего не выходит за край — не видна. Наведение — рамка цветом этапа и сдвиг на 1px
(120 мс); при переносе карточки над колонкой этапа станция получает ореол и один раз «подпрыгивает» (единственная
анимация «поп» доски). Все движения выключаются `prefers-reduced-motion`. Перетаскивание (CDK, предпросмотр, место
падения, подписи) не менялось. Стрелка подсказки — `content: '›' / ''` (пустой альтернативный текст: читалка её не
произносит); поле названия своей колонки — рамка `--app-border-w` и радиус `--app-radius-sm` вместо 1px/6px.
Тесты — `board.page.spec.ts` («route look»), `recruiting.restyle.spec.ts`.

### Общие хелперы Core (2026-10-02)
- трейт `Recruiting\Http\Controllers\Actor` перенесён в Core: текущий пользователь в контроллерах — общий трейт `Core\Http\Concerns\ResolvesActor` вместо приватной копии `actor()`;
- `perPage` списков — общий трейт `Core\Http\Requests\Concerns\Paginates`: правило `1..200`, по умолчанию 50, строка из query (`?perPage=20`) приводится к числу, вне диапазона или не число → 422 (`ListCandidatesRequest`, `ListVacanciesRequest`, `TimelineRequest`, `PerPageRequest` «Вхідних»);
- поиск `LIKE` экранирует `%`, `_` и сам символ экранирования через `Core\Support\Database\Like` (обратный слеш, `Like::contains`) (кандидаты, вакансии, команда найма).

Поведение API не менялось; подробности — [core.md](core.md), раздел «Общие хелперы модулей».

## Страница вакансий и офферы

**Страница вакансий (`/jobs`, `/jobs/:slug`)** — публичная, без входа и без сайдбара, логотип + переключатель uk/ru/en.
Рекрутер включает «Опублікувати на сторінці вакансій» и пишет «Опис для кандидатів» в диалоге вакансии
(`published`, `public_description`; slug `<транслит-названия>-<id>` присваивается при первой публикации). Видны только
опубликованные **открытые** вакансии; наружу уходят slug, название, филиал, должность, дата, публичное описание.

| Метод | Путь | Ответ |
|---|---|---|
| GET | `/api/public/vacancies` | список опубликованных открытых |
| GET | `/api/public/vacancies/{slug}` | вакансия или 404 |
| POST | `/api/public/vacancies/{slug}/apply` | multipart `name, email, phone?, message?, cv?, consent, website` → 201 |

Отклик: `consent` обязателен (согласие на обработку ПД, Закон 2297-VI; время согласия хранится), CV — PDF/DOC/DOCX ≤ 2 МБ
(тип по байтам, как файлы Documents, base64 в `career_submissions`). Кандидат — через `createOrMatch` (дедуп по e-mail/телефону,
первый этап воронки), `source=site` → канал «Career site», `added_via=career_site`, задача «позвонить новому» рекрутеру
вакансии. Антиспам: honeypot-поле `website` (заполнено → 201 без записи) и 5 откликов в час на HMAC-хэш IP (429
`too_many_requests`) + `throttle:30,1`. Модуль Recruiting выключен → публичные API отвечают 404.

**Офферы.** В карточке кандидата на заявке в этапе оффера (kind `hire`, не терминальный) — «Створити оффер»: шаблон
Documents категории `offer` (переменные `{ПІБ}`, `{Посада}`, `{Зарплата}`, `{Дата виходу}`, `{Умови}`, `{Філія}`,
`{Сьогодні}`), должность, зарплата, дата выхода, условия → текст в `offers` (один на заявку), статус `draft`.
«Надіслати» — письмо кандидату через Mailer (Gmail) тем же путём, что сообщения из карточки (исходящий touchpoint на заявке) → `sent`;
«Прийняв/Відмовився» рекрутер отмечает вручную → `accepted`/`declined`. Зарплата чувствительна: все эндпоинты оффера —
только `ApplicationPolicy::offer` (пишущие рекрутинг в своём скоупе + нанимающий менеджер вакансии), остальным 403.

| Метод | Путь | |
|---|---|---|
| GET | `/api/offer-templates` | шаблоны категории offer (recruiting-write) |
| GET/POST | `/api/applications/{id}/offer` | оффер заявки / создать (422 `not_in_offer_stage`, `template_not_offer`; 409 `offer_exists`) |
| POST | `/api/applications/{id}/offer/send` | отправить (422 `offer_status`) |
| POST | `/api/applications/{id}/offer/decision` | `{status: accepted\|declined}` |

## Личная доска на «Кандидатах» (Список | Дошка)

**Зачем.** Рекрутеру удобно раскладывать кандидатов «под себя» («Перезвонить в пт», «Жду резюме», «Топ»), не ломая общую
воронку, которую видят все.

**Как пользоваться.** `/candidates` → переключатель **«Список | Дошка»** (выбор запоминается в браузере) → выбрать вакансию.
Слева — общие этапы воронки этой вакансии (как на доске вакансии), справа — свои колонки: **«+ Колонка»**, меню колонки
(переименовать, цвет, сдвинуть влево/вправо, скрыть, удалить), **«Налаштувати дошку»** — вернуть скрытые колонки и
«Скинути до типових». Карточку можно тащить мышью или через кнопку **«Перемістити в…»** (клавиатура, телефон). На телефоне
колонки листаются вбок с «прилипанием».

**Порядок колонок — всё мышью.** Свою колонку тащат за «ручку» (значок ⋮⋮ в заголовке; на телефоне — долгое нажатие,
чтобы не мешать листанию) и ставят куда угодно: перед первым этапом, между этапами, после последнего; у края доска сама
прокручивается. Этапы воронки не перетаскиваются (замок с подсказкой «Етап воронки: порядок фіксований») — их порядок
всегда порядок воронки, свои колонки их только раздвигают. Новая колонка — кнопкой «+», которая появляется между колонками
при наведении (и «+ Колонка» в конце): ввести название, Enter — сохранить прямо там, Esc — отмена. «Посунути ліворуч/праворуч»
в меню колонки остались для клавиатуры и телефона и тоже перескакивают через этапы. Перенос карточки в колонку этапа
убирает её из своей колонки только после того, как сервер принял смену этапа (отказ — карточка остаётся где была).

Порядок хранится одним списком ключей `stage:<id>` / `col:<id>` в `candidate_board_layouts` (пользователь + вакансия).
Сервер проверяет: все ключи известны (этап этой воронки или своя колонка этой вакансии, иначе 422 `board_layout_invalid`),
этапы между собой в порядке воронки (иначе 422 `board_layout_stage_order`). При чтении список «чинится»: удалённые этапы и
колонки выпадают, этап, добавленный в воронку позже, встаёт сразу после предыдущего этапа, новые свои колонки — в конец.
Сохранение порядка — одна атомарная запись «вставить или обновить» по ключу пользователь + вакансия, поэтому два первых
сохранения одновременно (две вкладки, двойное перетаскивание) не падают с 500: выигрывает последнее. Список ключей — не
длиннее `PersonalBoardService::MAX_LAYOUT_KEYS` (100).

**Что видно, пока тащишь карточку.** Карточка «поднимается»: плотный фон, тень, лёгкий наклон, курсор — «рука».
На её месте (и там, куда она встанет) — пунктирная рамка того же размера, а не полупрозрачная копия. Колонка под курсором
подсвечивается и подписывает, что произойдёт при отпускании (подпись же зачитывает экранный диктор):
- **колонка этапа** — сплошная рамка цвета этапа (синий; найм — зелёный; отказ — красный) и подпись
  **«Змінити етап на «X»»**: это настоящая смена этапа для всех;
- **своя колонка** — серая пунктирная рамка и подпись **«Покласти в «X» (етап не змінюється)»**: только личный вид;
- **колонка текущего этапа карточки** (карточка лежит в своей колонке) — серая пунктирная рамка, **«Повернути в етап «X»»**;
- **нет прав менять этап** (не рекрутер/админ) — красная пунктирная рамка, **«Немає прав змінити етап на «X»»**; при
  отпускании карточка вернётся назад с сообщением об ошибке прав.

На телефоне карточку берут долгим нажатием (~0,3 с), чтобы свайп по карточке листал колонки, а не хватал её; пока карточка
в руке, «прилипание» колонок выключено, и доска сама прокручивается у края. Свою колонку при перетаскивании видно так же:
поднятая колонка и пунктирное место, куда она встанет. В «Налаштувати дошку» скрытые колонки идут под заголовком «Приховані
колонки — натисніть, щоб показати».

**Пока вакансия не выбрана** (или список вакансий ещё грузится, а запомненная вакансия ещё не подтверждена) — вместо доски
текст «Оберіть вакансію, щоб побачити дошку», без плитки «+ Колонка». Плитка появляется только на загруженной доске.
Если пользователь переключил вакансию, пока запрос ещё шёл, поздний ответ старой вакансии игнорируется: новая колонка
не попадает на чужую доску, а откат неудачной правки не возвращает на экран колонки прежней вакансии.

**Главное правило.** Своя колонка — только личный вид. Перенос карточки в свою колонку **никогда не меняет этап** кандидата:
на карточке остаётся бейдж с его настоящим этапом. Этап меняется только переносом в колонку этапа — это тот же
`POST /applications/{id}/move` с той же политикой, историей, аудитом и уведомлениями, что и на доске вакансии (наблюдатель
может раскладывать свои колонки, но этап не двигает — 403). Кандидат лежит максимум в одной своей колонке или ни в одной
(тогда — в колонке своего этапа). Удалили или скрыли колонку — её карточки вернулись в колонки этапов. Другие пользователи
чужих колонок не видят.

**Как устроено.** Таблицы `candidate_board_columns` (user_id, scope_vacancy_id, title ≤ 40, color из палитры, position,
hidden) и `candidate_board_cards` (user_id, application_id, column_id; уникально по user+application). Не больше 12 своих колонок
на вакансию (`board_column_limit`). Эндпоинты (`PersonalBoardController`, `Services/PersonalBoardService`):
`GET|DELETE /vacancies/{id}/personal-board` (прочитать / сбросить), `POST /vacancies/{id}/personal-board/columns`,
`PUT /vacancies/{id}/personal-board/layout {keys}` (порядок колонок, см. ниже), `PATCH|DELETE /personal-board/columns/{id}`,
`PUT /applications/{id}/personal-column {column_id|null}`. Доступ — как у `GET /vacancies/{id}/board` (право видеть вакансию);
сами карточки доска берёт из того же `GET /vacancies/{id}/board`, поэтому показывает ровно тех, кого пользователь и так видит.
Чужая колонка ищется «по своему user_id» → 404 (нет IDOR); колонка другой вакансии → 422 `board_column_mismatch`.
В журнал действий не пишется (личное состояние вида). Персональных данных в этих строках нет: при обезличивании кандидата они
остаются; при удалении пользователя, вакансии или отклика удаляются каскадом. Фронт: `board/board.page.ts` с входом
`personal`, `board/board.store.ts` (оптимистичный перенос с откатом), `candidates/candidates-view.ts`.

### Сортировка и фильтры таблиц отчётов и источников (2026-10-02)
Клик по названию колонки сортирует (повторный — в обратную сторону), воронка рядом — фильтр колонки; общий компонент `core/ui/table` (клиентская таблица `ClientTable`: все строки уже пришли, сравнение строк по языку интерфейса, пустые — в конце). Состояние — в адресе страницы с префиксом таблицы, ссылкой можно поделиться. Подключение — [guides/tables.md](../guides/tables.md).
- `/reports`: касания (рекрутер — текст; каналы, «з SinHRM», «зовні» — сортировка; «Усього» — диапазон; порядок по умолчанию — по убыванию итога), источники (источник — выбор, кандидаты и наняты — диапазон), причины отказа (появилась строка заголовков: «Причина», «Кількість»; порядок API — по убыванию). Строки «Разом» остаются внизу и в сортировке не участвуют; это итог всего отчёта (API / все рекрутеры), поэтому при включённом фильтре колонки подпись — «Разом (усі рядки звіту)» (`recruiting.reports.grandTotalAll`), как в `reports/report-table.ts`; иконки каналов — в заголовках. Адрес — `tch_*`, `src_*`, `rej_*`; период сверху и поиск по воронке не тронуты. Фильтры живые (2026-10-03): «Рекрутер» и «Причина» сужают строки на каждую букву без Enter и без запроса к API (адрес догоняет через 250 мс), выбор «Джерело» и диапазоны применяются сразу, без кнопки; диалог фильтра объявляет «Знайдено: N» (`appTableSortCount` у всех трёх таблиц и у панели источников вакансии).
- Панель источников вакансии (`/vacancies/:id`): канал, способ добавления (выбор), количество, доля; порядок API — по количеству. Ссылка с параметрами `src_*` открывает свёрнутую панель сразу.
- Тесты: `reports/reports.page.spec.ts`, `board/vacancy-sources.spec.ts`.

### Общие примитивы фронта
Общий код фронта лежит в `frontend/src/app/core` ([core.md](core.md)); фича его только вызывает.
- Ошибки API → i18n-ключ: `recruitingErrorKey` — свой случай `duplicate_restricted`, остальное через общий `apiErrorKey` (`core/api/api-error.ts`; статусы 403/422, запасной `recruiting.errors.generic`). Ключ каналов привлечения переименован в `acquisitionChannelErrorKey` (было `channelErrorKey`, совпадало с именем в фиче channels). Подсказка ИИ под разделом вакансии — `aiTextErrorKey` из `features/ai/ai.service.ts` (раньше функция `aiErrorKey` жила в `vacancy-form.page.ts` и дублировала имя из фичи ai).
- Короткие уведомления (toast) — `NotifyService.show(key, { params?, duration? })` из `core/ui/notify.service.ts` вместо своего `toast()` с `MatSnackBar`; тексты, длительности и доступность (вежливая live-область snack bar) прежние.
- HTTP-сервис фичи снимает обёртку ответа `{ data }` общим оператором `unwrapData()` (`core/api/unwrap-data.ts`, тип `DataEnvelope<T>` из `core/api/api.model.ts`) вместо своего `map((r) => r.data)`; параметры запроса без пустых значений — `toParams` из `core/api/http-params.ts`, страница списка — `Paged<T>` оттуда же. Контракт API не менялся.
- Компоненты не ходят в HTTP сами: оффер — через `card/offers.service.ts` (`OffersService`), публичные страницы вакансий — через `careers/careers.service.ts` (`PublicCareersService`); запросы и ответы прежние.

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
Ранжирование: `ScreeningRankingApiTest` (последняя попытка, pending/failed, null/0, равные баллы, максимум активных заявок, фильтры, пагинация, права, видимые отклики для филиалов/нанимающего менеджера/интервьюера и скрытие чужих откликов из оценки, фильтра и карточки, отсутствие AI-вызовов); фронт — `screening-ranking.spec.ts` и `screening-ranking.stores.spec.ts` (устойчивый порядок, независимые общие/личные колонки, переключение и перенос карточки), `board.page.spec.ts` (единственная кнопка с `aria-pressed`, восстановление порядка).
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

## Полная форма вакансии и «активная» вакансия (2026-10-25)
**Что изменилось.** Маленькое окно вакансии заменено страницей формы: `/vacancies/create` и `/vacancies/:id/edit`
(список остаётся на `/vacancies`). Одна колонка карточек (до 720 px), внизу липкая панель «Зберегти шаблон» / «Зберегти».

**Что в форме.**
- Шапка: метка «Вакансія», рекрутер (список из `/api/recruiting/assignable-users`), большое поле названия.
- Категория (справочник `vacancy_categories` из Directory), рекрутинговый трек (воронка; по умолчанию — основная,
  после создания не меняется), филиал, отдел.
- Разделы «Опис вакансії», «Вимоги», «Обов'язки», «Додаткова інформація» — Markdown с простой панелью (жирный,
  курсив, списки, очистить) и кнопкой «Створити з ШІ» (см. [ai.md](ai.md)). На публичной странице разделы
  рендерятся на сервере `MarkdownRenderer` (как статьи базы знаний): сырой HTML экранируется, опасные ссылки отбрасываются.
- «Умови роботи»: тип занятости, формат (офис/удалённо/гибрид), страна, город (справочник городов), опыт,
  образование, зарплата от/до + валюта (UAH по умолчанию, USD, EUR) + «Показувати кандидатам», иностранные языки
  (язык + уровень A1–C2 / родной).
- «Параметри публікації»: статус, публикация на `/jobs` (slug показывается), ссылки на объявления на внешних сайтах
  (Work.ua, Robota.ua, Djinni, DOU, LinkedIn, другое + адрес + дата). Это только ручные ссылки для учёта источников:
  у этих сайтов нет API для работодателей, интеграций нет.
- Ошибки проверки показываются у полей (ответ 422), черновик формы сохраняется в браузере (localStorage), при уходе
  с несохранёнными правками страница спрашивает.

**Шаблоны.** «Зберегти шаблон» сохраняет содержимое формы в `vacancy_templates` (без филиала, людей, статуса и
публикации). «Створити з шаблону» — в списке вакансий; открывает форму с заполненными полями.
Пользоваться шаблонами могут все пишущие роли рекрутинга; переименовать (`PATCH`) или удалить шаблон — только его
автор (`created_by`) и админы (superadmin/admin), `VacancyTemplatePolicy`, иначе 403. У старых шаблонов без автора —
только админы. В ответе у каждого шаблона `can_manage`; кнопки «Перейменувати»/«Видалити» в меню шаблонов видны
только при `can_manage = true`.

**Раскладка формы (2026-10-26).** Категория / трек / филиал / отдел — сетка в 2 колонки (1 колонка уже 600 px),
длинные значения обрезаются многоточием, у пунктов подсказка `title`. Подсказка «Категорії додаються в Довідниках»
(когда категорий нет) — строкой под сеткой, у админов со ссылкой на `/admin/directory`. Нижний отступ страницы
больше высоты липкой панели — панель не закрывает последнюю карточку.

**Публичная страница `/jobs/:slug`.** Кроме описания — разделы «Вимоги», «Обов'язки», «Додаткова інформація»
(`*_html` из API, выводятся через `[innerHTML]`: Angular-санитайзер не обходится, `<script>` и `on*` вырезаются —
тест `careers.spec.ts`), чипы город / тип занятости / формат и вилка зарплаты с валютой — только если API прислал
`salary` (то есть `salary_visible`).

**Списки значений.** Типы занятости, форматы, опыт, образование, языки и уровни, страны, валюты, сайты — общие
списки, не данные компании: они в коде (`Support/VacancyOptions`) и отдаются `GET /api/vacancy-options`; подписи
переводит интерфейс. Справочники компании (филиалы, отделы, города, категории) — только в базе, через Directory.

**Зарплата.** Внутри системы видна всегда. Публичный `/api/public/vacancies` отдаёт `salary` только при
`salary_visible = true`, иначе `null`. В журнале действий суммы не пишутся (как у компенсаций).

**Активная вакансия** = статус `open` **и** опубликована на `/jobs`. Одно правило для всех: `Vacancy::scopeActive()`
(список, фильтр, публичная страница) и `is_active` в ответе API. В списке — метка у каждой вакансии: зелёная
«Активна» или приглушённая с причиной («Відкрита, не опублікована», «На паузі», «Закрита»; статуса «чернетка» в
системе нет). Фильтр «Активні» — `GET /api/vacancies?active=1`, счётчик активных в шапке — `meta.active_count`
(в пределах филиалов пользователя).

**Эндпоинты.** `GET /api/vacancy-options`; `GET|POST /api/vacancy-templates` (пишущие роли рекрутинга),
`PATCH|DELETE /api/vacancy-templates/{id}` (автор или админ); `POST /api/vacancy-text` (throttle 20/мин; `category_id`
и `branch_id` должны существовать и быть активными — иначе 422, филиал вне области пользователя — 403
`vacancy_out_of_scope`, как при создании вакансии), `GET /api/vacancy-text/{id}`.
Права на сохранение вакансии не менялись: `recruiting-write` в своих филиалах, правило наймающего менеджера прежнее.

**Как проверить.** `php artisan test --filter=VacancyFormApiTest` (форма, 422, шаблоны, активность, публичная
зарплата, экранирование, ИИ, права на шаблоны, проверка `vacancy-text`); фронт — `vacancy-form.spec.ts`,
`careers.spec.ts`.

## Сквозной HTTP acceptance
`tests/Feature/HiringRequests/HiringApiAcceptanceTest.php` связывает одобренную заявку и auto-vacancy
с HTTP create candidate/apply/move до hired/rejected и итоговыми scoped reports.
Чужой филиал служит контрольной группой; viewer/foreign не меняют этап. Повторная create/apply/move
и отказ без причины возвращают существующие ошибки, не добавляя кандидатов, заявок или истории.
Проверка работает на синтетическом PostgreSQL CI, без frontend/provider mocks и без live production данных.
### Видимость заявок общей карточки (PROD09)
Доступ к кандидату (включая owner/created_by) не открывает заявки других филиалов. List и detail
возвращают только заявки видимых филиалов, управляемых вакансий либо назначенного интервью.
Один SQL application scope используется для вложенных заявок, application-фильтров списка,
stage changes, касаний с application_id и application audit. Глобальные контакты/аудит кандидата
и касания без application_id сохраняются. HR/admin видят всё; запрещённая карточка сохраняет 403.
Ответы store/update/inbox create используют find без загрузки applications и не раскрывают вложенные заявки;
bulk/import возвращают результаты операций, а не CandidateResource с вложенными данными.
Совместимость с PR139: candidate screening_score агрегирует заявки отдельным SQL и должен также
получить Scope перед совместным выпуском. Фильтрация nested applications сама не закрывает этот агрегат;
combined regression должна проверить общую карточку с высокой оценкой скрытой заявки.

Скрининги скрытых заявок не читаются и не опрашиваются у брокера при открытии карточки.
Материалы чужой заявки не попадают в промпт видимой; текст промпта, версия и настройки провайдера не менялись.

### Совместимость с MySQL

Нормализованные телефон, e-mail и Telegram-контакт кандидата защищены уникальными индексами. Несколько кандидатов без контактов допустимы; повтор непустого ключа по-прежнему даёт `duplicate_candidate` (409). Сортировка по оценке скрининга ставит кандидатов без оценки в конец. Оба контракта проверяются feature-тестами в обязательном job `tests` (MySQL 8.4 — единственная БД, [ADR 0011](../adr/0011-mysql-only.md)). Уникальные индексы контактов — обычные MySQL unique (несколько NULL допустимы); PostgreSQL-ветка с частичным индексом `WHERE col IS NOT NULL` из миграции удалена, `external_id` касаний и `url` профилей — `utf8mb4_bin` без драйверных веток. Тест: `tests/Feature/Recruiting/RecruitingMysqlSchemaTest`.

**Переносимый SQL (2026-10-08).** Сортировка кандидатов по `screening_score` строится через `Core\Support\Database\Sql::orderByNullsLast` — пустые значения в конце в обоих направлениях, без драйверных веток в модуле ([ADR 0011](../adr/0011-mysql-only.md)).
