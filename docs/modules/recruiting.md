# Модуль Recruiting (вакансии, кандидаты, касания)

## Что это и зачем
Сердце SinHRM. Рекрутер в одной карточке кандидата видит:
- **маршрут** — откуда кандидат пришёл (источник, UTM) → через какие этапы прошёл и сколько времени провёл на каждом → оффер, выход на работу или отказ с причиной;
- **все касания** — звонки, Telegram, WhatsApp, Viber, почта, заметки, встречи. И сделанные из SinHRM, и «перехваченные» снаружи
  (когда подключат интеграции): у каждого касания видно, откуда оно — «з SinHRM» или «зовні».

Руководитель видит **зависших кандидатов** (никто не связывался 3+ дня) и отчёты: кто из рекрутеров сколько касаний сделал и по каким
каналам, воронку по вакансиям, источники и причины отказов.

Воронки **настраиваемые**: у каждой вакансии своя воронка (набор этапов). По умолчанию — «Основна воронка» из 8 этапов
(как статусы Sintegrum `new → in_review → interview → offer → hired | rejected`, но подробнее).

## Как пользоваться
Меню слева → раздел «Рекрутинг»: **Кандидати**, **Вакансії**, **Вхідні**, **Звіти**. Везде работает **Ctrl/⌘+K** — быстрый переход
к кандидату (по имени, телефону, e-mail) или вакансии.

- **Вакансії** — список вакансий своих филиалов (по умолчанию открытые), поиск, фильтр статуса, «Нова вакансія» / ✎ (форма: название,
  филиал, должность, статус, описание). Клик по вакансии открывает **доску**: колонка на этап, карточки кандидатов перетаскиваются
  мышью между колонками. Перетащили в «Відмова» — система спросит причину (обязательно) и комментарий. Если перемещение не прошло —
  карточка вернётся и появится сообщение. Карточки без контакта 3+ дня отмечены значком ⏱ и текстом «N дн. без контакту».
  «Додати кандидата» — новый кандидат сразу на первый этап этой вакансии.
- **Кандидати** — слева список (поиск по имени/телефону/e-mail/@telegram, фильтры статуса и источника), справа карточка.
  Клавиши: `j`/`k` или `↓`/`↑` — следующий/предыдущий кандидат, `/` — поиск. Карточка: контакты (кликабельные), источник, UTM и теги;
  **Маршрут** по каждой вакансии (этапы с датой входа и длительностью, текущий подсвечен) и кнопка «Перемістити»; поле записи касания
  (канал, направление, текст, минуты для звонка/встречи; Ctrl/⌘+Enter — сохранить); **Касання** — лента новых сверху, фильтр-чипы
  по каналам и «Етапи».
  При создании кандидата с уже известным телефоном/e-mail/Telegram система предложит открыть существующую карточку или всё-таки
  создать нового.
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
| `candidates` | `full_name, phone (E.164), email (lowercase), telegram_username (lowercase, без @), city_id?, source, utm (jsonb), tags (jsonb), owner_id, created_by` | индексы на трёх контактах — ключи дедупликации |
| `applications` | `candidate_id, vacancy_id, stage_id, status (active\|hired\|rejected), reject_reason_id?, rejected_note, stage_entered_at, last_touch_at, closed_at` | `unique(candidate_id, vacancy_id)` |
| `stage_changes` | `application_id, from_stage_id? (null = создание), to_stage_id, by_user_id?, reason, at` | маршрут кандидата |
| `touchpoints` | `candidate_id?, application_id?, branch_id?, stage_change_id?, channel, direction (in\|out), author_id?, occurred_at, body, meta (jsonb: duration_sec, recording_url, contact), external_id, via_product, integration_key` | `unique(channel, external_id)` — дедуп повторной доставки (NULL не конфликтуют) |
| view `unmatched_messages` | `SELECT * FROM touchpoints WHERE candidate_id IS NULL` | для SQL/BI; API читает саму таблицу. ⚠ `SELECT *` фиксирует колонки при создании: изменение `touchpoints` потребует пересоздать view в той же миграции |

Enum-ы: `Enums/StageKind`, `VacancyStatus`, `ApplicationStatus`, `Channel` (`MANUAL` — каналы ручной записи, `isTouch()` = не `system`),
`Direction`, `CandidateSource` (`manual, work_ua, robota_ua, djinni, meta_ads, site, referral, telegram, import, inbox, other`), `TimelineItemType`.

### Правила
- **Статус заявки = тип этапа.** Терминальный `closed` → `rejected` (нужен `reject_reason_id`, иначе 422 `reject_reason_required`),
  терминальный `hire` → `hired`, любой другой → `active` (возврат из терминального этапа снова открывает заявку и очищает причину).
  Переход разрешён в любой этап **воронки этой вакансии**, кроме текущего (`same_stage`, `stage_not_in_pipeline` — 422).
- **Каждый шаг маршрута** = строка `stage_changes` + «системное» касание (`channel=system`, `stage_change_id`). В ленте карточки
  показывается сам шаг, системное касание не дублируется.
- **Дедупликация кандидата** (`Services/CandidateService::create`): телефон нормализуется в E.164 (`067 123-45-67` → `+380671234567`),
  e-mail — в нижний регистр, Telegram — без `@`/`t.me/`. Совпадение по телефону **или** e-mail **или** Telegram → 409
  `{code: "duplicate_candidate", existing_id, matched_by}`. `force_new: true` — создать всё равно. При правке контакт, занятый другим
  кандидатом, → тот же 409 (без force). Нормализация — `Support/ContactNormalizer`.
- **Импорт-готовый DTO:** `DTO/CandidateData::fromArray(array)` принимает «грязную» строку (таблица, выгрузка job-сайта):
  обрезает пробелы, чистит UTM/теги, неизвестный источник → `import`.
- **Зависшие:** `applications.last_touch_at` обновляет слушатель `Listeners/UpdateLastTouch` на событие `Events/TouchpointRecorded`
  (ручная запись, приём от интеграции, привязка из «Вхідних»). `system` не считается; время только двигается вперёд. Касание без
  заявки обновляет все активные заявки кандидата. «Завис» = активная заявка, у которой `coalesce(last_touch_at, created_at)` старше N
  дней (`Services/StalenessService`, по умолчанию 3).

### Доступ (`Services/RecruitingScope` + `Policies/*`)
| Роль | Видит | Может менять |
|---|---|---|
| superadmin, admin | всё | всё; воронки и причины отказов (gate `recruiting-manage`) |
| recruiter | вакансии своих филиалов (`AccessibleBranches`), их заявки и кандидатов + кандидатов, которых он создал/ведёт; «Вхідні» своих филиалов и свои | вакансии/кандидатов/заявки в этих пределах |
| viewer | как recruiter | ничего (403) |
| заблокированный / без филиалов | ничего (403 / пустые списки) | — |

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
| `POST /api/vacancies/{id}/applications` | `{candidate_id}` | 201; повтор → 409 `already_applied` |
| `GET /api/candidates` | `q` (имя/e-mail/@telegram/цифры телефона), `vacancy_id, stage_id, status, source, owner_id, perPage, page` | с краткими заявками |
| `POST /api/candidates` | `{full_name, phone?, email?, telegram_username?, city_id?, source?, utm?, tags?, owner_id?, vacancy_id?, force_new?}` | 201 / 409 дубль |
| `GET /api/candidates/{id}` | — | карточка + `applications[]` с `route[]` (`stage_name, entered_at, left_at, duration_sec, by, reason`) и `stages` |
| `PATCH /api/candidates/{id}` | частично; `vacancy_id`, `force_new` запрещены | 200 / 409 |
| `GET /api/candidates/{id}/timeline` | `channel=call,telegram,stage` (или массив; `stage` = шаги), `perPage, page` | новые сверху: `{type: touchpoint\|stage_change, at, touchpoint\|stage_change}` |
| `POST /api/candidates/{id}/touchpoints` | `{channel (note\|call\|meeting\|telegram\|whatsapp\|viber\|email), direction?, body (обязателен для note), occurred_at?, duration_sec?, application_id?}` | 201, `via_product=true` |
| `POST /api/applications/{id}/move` | `{stage_id, reason?, reject_reason_id?}` | заявка; ошибки см. «Правила» |
| `GET /api/recruiting/stale` | `days` 1..365 (строка `"3"` ок; по умолч. 3) | до 200 заявок, самые старые сверху; `meta.days` |
| `GET /api/inbox` | `perPage, page` | касания без кандидата в пределах доступа |
| `POST /api/inbox/{touchpoint}/link` | `{candidate_id}` | касание; уже привязано → 409 `already_linked` |
| `POST /api/inbox/{touchpoint}/create-candidate` | `{full_name, vacancy_id?, force_new?}` | 201 кандидат (source `inbox`), дедуп как при создании |
| `GET /api/reports/touches` | `from, to` (`YYYY-MM-DD`, по умолч. 30 дней, не больше года) | строки `author × channel × via_product`, `totals {total, via_product, captured}` |
| `GET /api/reports/funnel` | `from, to, vacancy_id?` | заявки, созданные в периоде, по вакансии × текущему этапу |
| `GET /api/reports/sources` | `from, to` | кандидаты периода по источнику + сколько из них `hired` |
| `GET /api/reports/reject-reasons` | `from, to` | отказы (по `closed_at`) по причинам |

Ошибки бизнес-правил — `Exceptions/RecruitingException` → `{message, code, …}`.

### Контракт для интеграций (приём касаний)
```php
interface TouchpointIngestor { public function ingest(IncomingMessage $message): Touchpoint; }
// DTO/IncomingMessage: channel, direction, occurredAt, contact (телефон | e-mail | @username | t.me/…),
//                      body?, externalId?, integrationKey?, branchId?, authorId?, viaProduct=false, meta[]
```
Реализация `Services/MatchingTouchpointIngestor`: (1) есть `externalId` и такое касание в этом канале уже есть — вернуть его
(повторная доставка вебхука безопасна); (2) `ContactNormalizer::guess(contact)` → поиск кандидата по телефону/e-mail/Telegram;
(3) нашли — привязка к последней активной заявке, событие `TouchpointRecorded` (обновит «зависание»); не нашли — касание остаётся в
«Вхідних» (`candidate_id = null`), `branchId` линии/аккаунта определяет, каким рекрутерам его видно. Сейчас используется тестами и
демо-данными; вебхуки Binotel/Ringostat/Telegram/WhatsApp/Viber/Gmail будут вызывать его же (модуль Integrations).

### Демо-данные
`php artisan recruiting:demo` — только не в production (там — ошибка и код 1); повторный запуск ничего не делает (маркер —
пользователь `demo-recruiter-1@example.test`). Создаёт, если в БД нет хотя бы двух активных филиалов, 3 демо-филиала/2 города/
3 должности; 3 демо-пользователя (admin + 2 recruiter, привязаны к филиалам) и viewer; 5 вакансий; **40 кандидатов** на разных этапах
(часть отклонена с причиной, часть принята) с касаниями **по всем каналам** — и «из SinHRM», и «извне»; ~8 зависших; 6 сообщений в
«Вхідних». Имена — сочетания общих имён/фамилий из списка в коде (Faker — dev-зависимость, на деплое его нет), e-mail на
зарезервированном домене `example.test`, телефоны выдуманные. Всё создаётся через настоящие сервисы.
Preview: `POST /api/ops/migrate?fresh=1` → `migrate:fresh --seed` → `DatabaseSeeder` вызывает `recruiting:demo`, если `APP_ENV != production`.

### Слои
`Http/Controllers/*` (оркестрация) → `Http/Requests/*` (валидация + `authorize()`) → `Services/*` → `Contracts/*Repository`
(`Repositories/Eloquent*`, `QueryReportRepository`). Привязки — `Providers/RecruitingServiceProvider`.

### Фронтенд (`frontend/src/app/features/recruiting`)
| Файл | Что |
|---|---|
| `recruiting.model.ts`, `recruiting.service.ts` | типы API, HTTP-клиент, `recruitingErrorKey`, `duplicateOf` |
| `recruiting.format.ts`, `recruiting.access.ts` | длительности, группировка по этапам, статус этапа, диапазон дат; `canWriteRecruiting` |
| `vacancies/` | список + `VacancyDialog` (`/vacancies`) |
| `board/` | доска CDK drag&drop (`/vacancies/:id`), оптимистичный перенос с откатом, `RejectDialog` |
| `candidates/` | split view (`/candidates`, `/candidates/:id`), клавиши j/k/↑/↓//, `CandidateDialog` с обработкой дубля |
| `card/` | карточка: маршрут, перемещение, лента с фильтрами, `TouchComposer` |
| `inbox/` | `/inbox` + `InboxResolveDialog` (привязать / создать) |
| `reports/` | `/reports`, таблицы с CSS-полосками, `pivotTouches` |
| `palette/` | `CommandPalette` в CDK overlay (`CommandPaletteService`), Ctrl/⌘+K — в оболочке ([shell.md](shell.md)) |

Строки — `recruiting.*` и `palette.*` в `public/i18n/{uk,ru,en}.json`. Общие стили страниц (`.page-head`, `.filters`, `.panel`, `.state`)
и токен `--app-warning` — в `styles.scss`.

## Как проверить
Бэкенд: `tests/Feature/Recruiting/*` — вакансии (401/403, филиалы, роли, фильтры, доска, добавление), кандидаты (нормализация,
дубль 409 по трём ключам, `force_new`, поиск, карточка с маршрутом и длительностями, права), перемещения (stage_change + системное
касание, причина отказа, hired, чужая воронка, роли), лента (порядок, фильтр, пагинация, ручное касание и `last_touch_at`),
«Вхідні» (область видимости, привязка, создание, 409), зависшие (`days` строкой, валидация, филиалы), отчёты (суммы, период),
воронки и причины, демо-команда (данные, повтор, отказ в production). `tests/Unit/Recruiting/*` — нормализатор контактов,
ingestor (сопоставление, дедуп, «Вхідні»), `CandidateService` (моки), `ApplicationService`.
Фронт: `recruiting.service.spec.ts`, `recruiting.format.spec.ts`, `recruiting.stores.spec.ts`.

Вручную на preview (нужна сессия; демо-данные уже в БД):
```bash
curl -i "https://<preview>/api/recruiting/stale?days=3"      # без сессии → 401
```
**Не проверено в этой задаче:** реальные запросы к preview/prod (вход только через Google на prod-домене), запросы на Postgres
локально (тесты гонялись на SQLite; CI — Postgres).
