# SinHRM — задачи до готовности к production

Обновлено 2026-10-05. Внутренние ID PROD не являются Jira-карточками. Источник продуктовой цели — [единое ТЗ](UNIFIED-TZ.md); этот backlog описывает выполнение и доказательства, не утверждает неизвестные бизнес-решения. Работа разрешена владельцем: брать задачи раундами; последнее указание передаёт исполнение недорогой Luna, Astra проверяет локальный и GitHub diff перед merge.

### Граница поставки, подтверждённая владельцем

Наша поставка — готовые frontend/backend, интеграция сотрудников и авторизации с экосистемой Itstep, миграции и инструкция развёртывания. Владелец — superadmin и принимает продуктовые решения. Целевая production инфраструктура — Itstep; серверы, резервные копии, восстановление, секреты окружения и alerts настраивают DevOps при переносе. Эти настройки не являются вопросами к владельцу в рамках нашей разработки. Текущие Vercel/Neon — существующая среда, не утверждённая целевая инфраструктура. Реальный запуск на Itstep принимается отдельно после передачи приложения DevOps.

## Что означает Ready to production

Статус присваивается **конкретному release SHA, организации, набору включённых модулей и интеграций** после выполнения критериев ниже. Текущий продукт не имеет tenant-модели; несколько организаций в одной базе нельзя объявить безопасным сценарием по тестам филиалов. Граница пилота и роли должны быть записаны до приёмки. Полный список возможностей конкурента не является критерием выпуска.

1. Объём, пилотная организация, роли, владельцы данных и разрешённые внешние потоки определены; первичное исследование и критерии ценности записаны без выдуманного спроса.
2. Для включённых сценариев нет открытых критических дефектов доступа, приватности или потери данных. Явно отключённый User теряет поддерживаемые сессии/токены; восстановление не оживляет отозванные credentials. Автоматический Employee lifecycle принимается по согласованному контракту, если включён в scope. При ручном отключении User остаётся явная проверка назначенных задач; D1–D3 и автоматизация могут быть отложены.
3. Один выбранный пилотный workflow проходит на известных синтетических данных через реальные API с отрицательными правами, повторами и ошибками. Полный путь заявка → вакансия → кандидат → решение → сотрудник/адаптация и связанные отчёты обязательны только если входят в scope. UI fixtures подтверждают интерфейс, но не живой внешний сервис.
4. Shipping commit имеет зелёные required checks, независимый Astra verdict, содержательную документацию и журнал. Каждая feature/fix живёт в собственной ветке. Проверочные объединения PR142/144 не служат shipping commits.
5. Выбранные внешние потоки прошли реальную разрешённую приёмку; для неподключённых функций явно записан статус. PROD-19–22 и PR138–141/143 применяются к release только при включении соответствующей функции. Для включённого AI качество проверено отдельно от deterministic broker fixtures, чувствительные данные минимизированы.
6. Для передачи DevOps описаны версии runtime, сборка frontend/backend, миграции, очередь/расписание, файлы, health и имена конфигурации без значений секретов. Приложение не требует Vercel как единственного способа исполнения. DevOps отвечает за production резервные копии, восстановление, секреты окружения и alerts; их инфраструктурная приёмка не подменяет готовность приложения.
7. До выкладки определены stop/rollback, ответственное лицо и точные версии Web/API; после разрешённой выкладки выполнены smoke и изменённые пользовательские сценарии на обычном URL.

## Правила работы раундами

Каждый раунд берёт ограниченное число независимых задач. Перед кодом — краткий план/контекст и `## Стан` в `docs/tasks/PROD-*.md`; перед handoff состояние обновляется. Luna завершает code/tests/docs → push/draft PR → CI → отдельная проверка выводов → Astra проверяет локальный и GitHub shipping diff → разрешённый merge/deploy → live proof. Полные suites идут в GitHub Actions; локально только минимальные релевантные проверки. Нельзя молча превращать OWNER_PENDING в выбранный контракт. Зелёный CI не закрывает live gates.

Статусы: **IN_PROGRESS**, **QUEUED**, **PR_READY**, **OWNER_PENDING**, **EXTERNAL_PENDING**, **DONE**. DONE требует доказательства критерия, а не только написанного кода.

## Раунд 1 — основа проверяемого выпуска

| ID | Задача | Критерий завершения | Состояние / исполнитель |
|---|---|---|---|
| PROD-00 | Единый production backlog и точная документация процесса/индекса | Критерии/зависимости/все долги перечислены; старые ложные сведения про отчёты/Clipper/HR исправлены; модельный workflow соответствует инструкции владельца | IN_PROGRESS · [PR147](https://github.com/zapleoceo/sinhrm/pull/147);37f95bd Luna/Astra PASS и10 checks SUCCESS; текущий phase update требует проверки |
| PROD-01 | Локализация даты журнала интеграций | uk/ru/en, browser timezone, null/invalid без ложной даты; targeted unit и synthetic E2E/CI, сохранённые элементы UI | PR_READY · [PR146](https://github.com/zapleoceo/sinhrm/pull/146),8acfadb:10 checks SUCCESS, Astra PASS; synthetic desktop/mobile PNG просмотрены |
| PROD-02 | Provenance Web/API сборки | Безопасный immutable build SHA от фактического checkout, health contract сохранён, preview не ошибочно получает main SHA; тесты валидных/невалидных данных и stamping | PR_READY · [PR145](https://github.com/zapleoceo/sinhrm/pull/145),88dd8b7:10 checks SUCCESS, Astra PASS; не в production |
| PROD-03 | Исправить границу локальных суток workflow | Luna нашла общую причину failures133–136: `UserTime.php:47` и `WorkflowStarter.php:77` превращают локальный день Kyiv в midnightUTC; zero-offset шаг становится будущим между21–24UTC при UTC+3. Исправить owning scheduler и добавить deterministic boundary/DST/offset tests, не ослаблять assertions | PR_READY · [PR148](https://github.com/zapleoceo/sinhrm/pull/148),df397ed:10 checks SUCCESS, Astra PASS; существующие due_at не переписаны |

## Раунд 2 — безопасность и совместимость

| ID | Задача | Критерий завершения | Состояние / зависимость |
|---|---|---|---|
| PROD-04 | @types/node, [PR133](https://github.com/zapleoceo/sinhrm/pull/133) | Актуальный head, совместимость зафиксирована, CI зелёный, Astra review | QUEUED · после03; отдельная ветка |
| PROD-05 | Scramble, [PR134](https://github.com/zapleoceo/sinhrm/pull/134) | То же; API schema не теряет контракт | QUEUED · после03 |
| PROD-06 | typescript-eslint, [PR135](https://github.com/zapleoceo/sinhrm/pull/135) | То же; правила lint не ослаблены ради прохождения | QUEUED · после03 |
| PROD-07 | Laravel, [PR136](https://github.com/zapleoceo/sinhrm/pull/136) | То же; auth/session/DB regressions остаются зелёными | QUEUED · после03 |
| PROD-08 | Explicit User block: глобальный отзыв credentials | Сессии/PAT отозваны атомарно; stale grants после block/unblock не создают долговременный доступ; last-active-superadmin защищён; поддерживаемые drivers и legacy invalidation документированы | PR_READY · [PR149](https://github.com/zapleoceo/sinhrm/pull/149),2cd011a:10 checks SUCCESS, Astra PASS; PAT/OAuth races и legacy block закрыты credential generation |
| PROD-09 | Исправить candidate nested application scope | Фильтр разрешённых application rows применяется к list/show, stage history, application-bound touchpoints, audit и screenings до polling/serialization; prompt materials не содержат скрытые application touches; отрицательная role/cross-branch matrix | PR_READY implementation · [PR153](https://github.com/zapleoceo/sinhrm/pull/153),b27f6f4:10 checks SUCCESS, Astra PASS; новая state-only7b05362 требует финального CI/review; не в main |
| PROD-10 | Минимизация AI-данных и fresh access, [PR143](https://github.com/zapleoceo/sinhrm/pull/143) | Фактический release head reviewed/CI; чёткие допустимые данные/задачи; no replay writes из сохранённой истории диалога; ограничения tenant/exactly-once/уже показанной истории честно приняты | PR_READY · Draft, не в main; live quality в20; условно по scope |

## Раунд 3 — lifecycle и операционная безопасность

| ID | Задача | Критерий завершения | Состояние / зависимость |
|---|---|---|---|
| PROD-11 | Зафиксировать D1: identity/организация/занятость | Полномочный источник, явная mapping, один/несколько Employee/positions, admin без Employee и граница org утверждены; no guessed email mapping | OWNER_PENDING · подготовить конкретные варианты по коду; tenant для multi-org — отдельная реализация |
| PROD-12 | Зафиксировать D2: увольнение/дата/rejoin | Immediate/scheduled, effective instant/timezone, late/backdated/cancel и reviewed restore выбраны | OWNER_PENDING |
| PROD-13 | Зафиксировать D3: handover/exit survey | Получатель работы и transfer/cancel/keep, timing survey, старые workflows при rejoin определены | OWNER_PENDING |
| PROD-14 | Реализовать согласованный Employee↔User lifecycle | При включении автоматизации T01–T14 актуализированы и пройдены: transaction/concurrency, idempotent event, disable/restore/manager scope/handover/last-superadmin, сбои внешних эффектов видимы | QUEUED ·11–13 и release scope; отдельные узкие feature ветки |
| PROD-15 | Синтетическая backup/restore проверка + runbook | Реальный dump/restore изолированной CI БД, сверка данных/связей/vault; DB proof не покрывает внешние файлы; инструкция передаётся DevOps; не читать production data | PR_READY · [PR150](https://github.com/zapleoceo/sinhrm/pull/150),081d2a1:11 checks SUCCESS, Astra PASS;20 seed/22 restore assertions; production restore не заявлен |
| PROD-16 | Передача эксплуатационной приёмки DevOps | При переносе DevOps настраивает production backup/restore, секреты, alerts и реакцию; приложение передаёт проверяемую инструкцию и health | EXTERNAL_PENDING · ответственность DevOps; не блокирует самостоятельную разработку frontend/backend; вопросы RPO/RTO владельцу сняты |

## Раунд 4 — сквозная приёмка и внешние потоки

| ID | Задача | Критерий завершения | Состояние / зависимость |
|---|---|---|---|
| PROD-17 | API end-to-end выбранного пилотного workflow | Известный dataset, успешный сценарий, повторы, ошибки и forbidden для выбранного пути; hiring/оффер/найм/адаптация и exact reports по периоду/scope проверяются в объёме включённого scope; обнаруженные дефекты закрыты | PR_READY · [PR151](https://github.com/zapleoceo/sinhrm/pull/151),4626e81:10 checks SUCCESS, Astra PASS; реальный HTTP путь заявки/вакансии/кандидата/решения/отчётов на синтетике; не полная live приёмка |
| PROD-18 | Матрица включённых модулей/ролей + UI | Проверки прав/ошибок/повторов/PII для каждого включённого модуля; desktop/mobile/light/dark, keyboard и реальные понятные сценарии; known skips перечислены | IN_PROGRESS · протокол и HTTP module boundary [PR152](https://github.com/zapleoceo/sinhrm/pull/152),4bf3524:10 checks SUCCESS, Astra PASS; полная матрица и live UI ещё не приняты |
| PROD-19 | Google reconnect/send/read/calendar/sheets | Разрешённый владелец аккаунта подтвердил gmail.send; проверены выбранный поток, expiry/refresh/denial/retry и безопасные ошибки | EXTERNAL_PENDING · UI/код в138; подготовка протокола самостоятельная |
| PROD-20 | Live AI usefulness/safety | Разрешённые синтетические задания и явные критерии полезности, отказа, стоимости/latency/human correction; no autonomous personnel decisions; провайдер проверен | EXTERNAL_PENDING · после10 и scope/data decisions |
| PROD-21 | Выбранные messaging/telephony потоки | API/договор/права, реальный обезличенный payload, send/read/webhook/retry/disable, дедуп и источник; каждый неподключённый provider отмечен отдельно | EXTERNAL_PENDING · выбрать сервисы; no fabricated live proof |
| PROD-22 | Clipper live calibration | Все заявленные5 площадок проверены на разрешённых страницах, extraction/dedup/auth/denial, ограничения документированы | EXTERNAL_PENDING · страницы работодателя; fixtures остаются synthetic |
| PROD-23 | Первый сегмент/интервью/метрики пилота | Роли/организация/ценность/явные не-желания, baseline и применимые к выбранному пилоту S-критерии, обезличенный протокол и пользовательская приёмка | OWNER_PENDING / EXTERNAL_PENDING · public reviews уже доступны, контакты и пилот ещё нет |

## Раунд 5 — подготовленные изменения и shipping

| ID | Задача | Критерий завершения | Состояние / зависимость |
|---|---|---|---|
| PROD-24 | Source138 reconnect | Новый shipping SHA/CI/Astra, содержательная live приёмка выбранного UI/flow | PR_READY · [138](https://github.com/zapleoceo/sinhrm/pull/138),10/10 CI, Draft |
| PROD-25 | Source139 ranking | Scope применяется до max score, фильтра и сортировки; скрытая application не влияет на видимый score; ties/null/pagination; reviewed shipping SHA, live UI проверка | PR_READY implementation · [139](https://github.com/zapleoceo/sinhrm/pull/139),cc7b9ff:10 checks SUCCESS, Astra PASS; новая state-only2cdb496 требует финального CI/review; старый227046d больше не evidence готовности |
| PROD-26 | Source140 mobile mascot | Нет перекрытия changed content/confirmation на mobile; browser limitations перечислены | PR_READY · [140](https://github.com/zapleoceo/sinhrm/pull/140),10/10 CI, Draft |
| PROD-27 | Source141 paginator | uk/ru/en labels/range, нулевые/пустые страницы, normal layout | PR_READY · [141](https://github.com/zapleoceo/sinhrm/pull/141),10/10 CI, Draft |
| PROD-28 | Combined regression / validation docs | Выбранный shipping набор проверен совместно, конфликты/переводы/inventory не потеряны; lifecycle drafts остаются честно pending | IN_PROGRESS · новая совместная проверка ожидает09/25;142/144 покрывают старый набор и остаются DO NOT MERGE |
| PROD-29 | Release/rollback/live acceptance | Конкретный пакет, разрешение production, точные squash SHA и Web/API deploy IDs, stop condition, rollback owner, normal-URL smoke и изменённые сценарии | QUEUED · все применимые gates выше; main автоматически выкладывается |

## Остальные хвосты — очередь продукта, влияние на release выбирается по evidence

Эти задачи также учтены, но новый scope не считается обязательным для production автоматически. Если функция входит в согласованную приёмку и без неё сценарий не работает, задача переносится в обязательный раунд с критерием и источником.

| ID | Хвост | Следующее действие / состояние |
|---|---|---|
| PROD-30 | TZ1 annual recurrence/reminders | QUEUED: проверить частоту, расписание и privacy reminders; отдельная фича |
| PROD-31 | TZ2 blocks/DnD/director/link | QUEUED: сценарный UX, затем минимальные самостоятельные задачи |
| PROD-32 | TZ3 job boards/lead forms/detail funnel | EXTERNAL_PENDING: реальный спрос и поддерживаемые API; не обещать двусторонний API без доказательства |
| PROD-33 | TZ4 reason types/stage/recruiter | QUEUED: подтвердить вопросы аналитики и taxonomy, затем ветка feat/tz4-reject-reasons |
| PROD-34 | TZ5 named mood/mascot decorations | OWNER_PENDING: именованная история требует цели/приватности; декоративное улучшение только по UX evidence |
| PROD-35 | КЭП / размер файлов / payroll / новые регионы | OWNER_PENDING / EXTERNAL_PENDING: подтверждённые сценарии, рынок, сервис/договор; не считать stub интеграцией |
| PROD-36 | Межмодульные зависимости/циклы | QUEUED: измерить текущий baseline и уменьшать отдельными модульными PR; не проводить весь legacy rewrite ради release |
| PROD-37 | Согласовать draft UNIFIED-TZv0.3 | OWNER_PENDING: reconcile с нынешним backlog и security evidence; v0.3 local draft `0dd175d` не считается принятой версией main |
| PROD-38 | Рабочие копии/артефакты | QUEUED: inventory выявил44 worktrees, включая активные и unmerged drafts; удаление требует подтверждённой ненужности и проверки ignored data, активные/evidence сохраняются |

## Обязательная подготовка приложения для Itstep

Источник нового требования — указание владельца от 05.10.2026: перенос на Itstep, сотрудники из СКУД, изучить связь как в Sintegrum. [Разбор и контракт поставки](itstep-integration.md) отделяет найденные механизмы от неизвестного протокола.

| ID | Задача | Критерий / состояние |
|---|---|---|
| PROD-39 | Пакет frontend/backend для DevOps Itstep | IN_PROGRESS · [PR155](https://github.com/zapleoceo/sinhrm/pull/155),e77b200 docs baseline: runtime/build/start/migrations/queue/scheduler/storage/health и границы текущего Vercel; CI/Astra pending, приложение не объявлено развёрнутым на Itstep |
| PROD-40 | Авторизация в целевой экосистеме | EXTERNAL_PENDING contract · текущий Sintegrum использует локальный пароль и opaque tokens, SSO этим не доказан. Владелец сообщил, что сервис/документацию пока дать не может; записать в долги, подготовить доступную часть кода; существующий login не заменять выдуманным SSO |
| PROD-41 | Импорт и сверка сотрудников СКУД | IN_PROGRESS preparation · владелец разрешил подготовить код сейчас, данные подключения даст позже. Отдельная feat/itstep-directory-preparation: disabled default, явная схема/namespace, typed gateway и безопасный preview/conflicts; полного live sync/изменения сотрудников по неизвестному feed не заявлять |
| PROD-42 | Связь аккаунтов и права импортированных сотрудников | QUEUED: source-scoped IDs, уникальность и reviewed conflicts; email не выдаёт ACL; владелец superadmin подтверждён, массовое назначение прав остальным не определено. Подготовить конкретный безопасный сценарий после40/41 |
| PROD-43 | Проектные правила экономии контекста | DONE · [PR154](https://github.com/zapleoceo/sinhrm/pull/154),00682bd:10 checks SUCCESS, Astra PASS; merged main38d90ea. Короткий AGENTS.md, узкие задачи Luna, дисковый state, краткие логи и exact-head review; новые shared rules master_v3b269a264 и sinhrm adapter прочитаны, автоматическое разрешение release не добавлено |

### Долги, которые владелец предоставит позже

| Долг | Зачем нужен | Что делаем до получения |
|---|---|---|
| Репозиторий/OpenAPI сервиса сотрудников и авторизации | Проверить фактический response/ACL и corporate login | Готовим выключенную интеграцию и контрактные проверки; текущий login сохраняет свой проверенный контракт |
| Canonical staff ID/namespace и project/branch mapping | Связать профили с внутренними employee/user без ошибочного объединения | Явные source-scoped IDs, конфликты и preview; не выдаём права по email |
| Схема ответа, total/termination пагинации, delta/deletion semantics | Доказать полный импорт и обработку изменений/удалений | Fail-closed при неизвестной/неполной выдаче; outage не увольняет сотрудников |
| Разрешённое подключение/token и live acceptance | Проверить реальный сервис после передачи данных | Секреты через vault/config; синтетические tests, network по умолчанию выключен; настройку окружения передаём DevOps |

Ответ владельца 05.10.2026: ссылки пока нет, записать в долги и подготовить код; остальное предоставит позже. Нового вопроса по тем же данным не требуется. Попытка GitLab metadata discovery через cached credential была отклонена автоматической policy до выполнения, без получения/передачи credential; обход не выполнялся.

## Источники среза

Main `3b4ac30`, [CI37212189230](https://github.com/zapleoceo/sinhrm/actions/runs/37212189230); Web production [37212621448](https://github.com/zapleoceo/sinhrm/actions/runs/37212621448), API [37055808795](https://github.com/zapleoceo/sinhrm/actions/runs/37055808795). [Combined CI37232005803](https://github.com/zapleoceo/sinhrm/actions/runs/37232005803) относится только к144 head `31bf076`. Модульные документы/тесты — evidence кода, не live acceptance. Старый worklog — исторический источник; актуальные release gates фиксируются здесь. Внешний локальный аудит: `D:/Projects/HRM/docs/project-status-2026-10-05.md`.

## Стан

2026-10-05: main38d90ea после docs-only154; feature merge/deploy не выполнялись.145/146/148/149/150/151/152 имеют зелёный exact-head CI и Astra PASS;153b27 и139cc7 implementation тоже прошли полный CI/Astra, task-state deltas требуют финального CI. Luna продолжает новую совместную регрессию и подготовку приложения/директории Itstep. Требования Itstep/superadmin включены в ТЗ, DevOps эксплуатационные настройки вынесены из нашей разработки. Внутренние SDK доступны через HTTPS, профильный endpoint найден; owner inputs записаны в долги, код разрешён к подготовке без живого подключения. Production readiness OPEN.
