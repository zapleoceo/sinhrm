# SinHRM — задачи до готовности к production

Обновлено 2026-10-06. Внутренние ID PROD не являются Jira-карточками. Источник продуктовой цели — [единое ТЗ](UNIFIED-TZ.md); этот backlog описывает выполнение и доказательства, не утверждает неизвестные бизнес-решения. Работа разрешена владельцем: брать задачи раундами; последнее указание передаёт исполнение недорогой Luna, Astra проверяет локальный и GitHub diff перед merge.

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
| PROD-01 | Локализация даты журнала интеграций | uk/ru/en, browser timezone, null/invalid без ложной даты; targeted unit и synthetic E2E/CI, сохранённые элементы UI | DONE (merged 2026-10-06, PR146) · [PR146](https://github.com/zapleoceo/sinhrm/pull/146),8acfadb:10 checks SUCCESS, Astra PASS; synthetic desktop/mobile PNG просмотрены |
| PROD-02 | Provenance Web/API сборки | Безопасный immutable build SHA от фактического checkout, health contract сохранён, preview не ошибочно получает main SHA; тесты валидных/невалидных данных и stamping | DONE (merged 2026-10-06, PR145) · [PR145](https://github.com/zapleoceo/sinhrm/pull/145),88dd8b7:10 checks SUCCESS, Astra PASS; не в production |
| PROD-03 | Исправить границу локальных суток workflow | Luna нашла общую причину failures133–136: `UserTime.php:47` и `WorkflowStarter.php:77` превращают локальный день Kyiv в midnightUTC; zero-offset шаг становится будущим между21–24UTC при UTC+3. Исправить owning scheduler и добавить deterministic boundary/DST/offset tests, не ослаблять assertions | DONE (merged 2026-10-06, PR148) · [PR148](https://github.com/zapleoceo/sinhrm/pull/148),df397ed:10 checks SUCCESS, Astra PASS; существующие due_at не переписаны |

## Раунд 2 — безопасность и совместимость

| ID | Задача | Критерий завершения | Состояние / зависимость |
|---|---|---|---|
| PROD-04 | @types/node, [PR133](https://github.com/zapleoceo/sinhrm/pull/133) | Актуальный head, совместимость зафиксирована, CI зелёный, Astra review | DONE · отклонено 2026-10-06: major @types/node 26 не соответствует runtime Node 24, [PR133](https://github.com/zapleoceo/sinhrm/pull/133) закрыт с игнорированием major |
| PROD-05 | Scramble, [PR134](https://github.com/zapleoceo/sinhrm/pull/134) | То же; API schema не теряет контракт | DONE (merged 2026-10-06, PR134) · после03 |
| PROD-06 | typescript-eslint, [PR135](https://github.com/zapleoceo/sinhrm/pull/135) | То же; правила lint не ослаблены ради прохождения | DONE (merged 2026-10-06, PR135) · после03 |
| PROD-07 | Laravel, [PR136](https://github.com/zapleoceo/sinhrm/pull/136) | То же; auth/session/DB regressions остаются зелёными | DONE (merged 2026-10-06, PR136) · после03 |
| PROD-08 | Explicit User block: глобальный отзыв credentials | Сессии/PAT отозваны атомарно; stale grants после block/unblock не создают долговременный доступ; last-active-superadmin защищён; поддерживаемые drivers и legacy invalidation документированы | DONE (merged 2026-10-06, PR149) · [PR149](https://github.com/zapleoceo/sinhrm/pull/149),2cd011a:10 checks SUCCESS, Astra PASS; PAT/OAuth races и legacy block закрыты credential generation |
| PROD-09 | Исправить candidate nested application scope | Фильтр разрешённых application rows применяется к list/show, stage history, application-bound touchpoints, audit и screenings до polling/serialization; prompt materials не содержат скрытые application touches; отрицательная role/cross-branch matrix | DONE (merged 2026-10-06, PR153) implementation · [PR153](https://github.com/zapleoceo/sinhrm/pull/153),7b05362:10 checks SUCCESS; implementation b27f6f4 и объединённая совместимость прошли независимый Astra review; не в main |
| PROD-10 | Минимизация AI-данных и fresh access, [PR143](https://github.com/zapleoceo/sinhrm/pull/143) | Фактический release head reviewed/CI; чёткие допустимые данные/задачи; no replay writes из сохранённой истории диалога; ограничения tenant/exactly-once/уже показанной истории честно приняты | DONE (merged 2026-10-06, PR143) · Draft, не в main; live quality в20; условно по scope |

## Раунд 3 — lifecycle и операционная безопасность

| ID | Задача | Критерий завершения | Состояние / зависимость |
|---|---|---|---|
| PROD-11 | Зафиксировать D1: identity/организация/занятость | Полномочный источник, явная mapping, один/несколько Employee/positions, admin без Employee и граница org утверждены; no guessed email mapping | DECIDED 2026-10-06: человек может быть и Employee, и User; admin без Employee допустим; одна учётка может иметь несколько ролей, переключатель роли в меню аватарки (уже есть «Працювати як»), multi-org отдельно · подготовить конкретные варианты по коду; tenant для multi-org — отдельная реализация |
| PROD-12 | Зафиксировать D2: увольнение/дата/rejoin | Immediate/scheduled, effective instant/timezone, late/backdated/cancel и reviewed restore выбраны | DECIDED 2026-10-06: увольнение с даты, которую задаёт менеджер (по цепочке руководителей), HR/админ тоже; уволенного можно восстановить в прежней или новой должности. Доступ снимается с эффективной даты (по умолчанию конец дня Kyiv) — реализация PROD-14 |
| PROD-13 | Зафиксировать D3: handover/exit survey | Получатель работы и transfer/cancel/keep, timing survey, старые workflows при rejoin определены | DECIDED 2026-10-06: передача дел — необязательное поле «на кого передать» при увольнении, заявке на отпуск и заявке на больничный; exit-опрос при увольнении нужен (форма/анонимность — по умолчанию именной, видит только HR; уточнить) — реализация PROD-14 |
| PROD-14 | Реализовать согласованный Employee↔User lifecycle | При включении автоматизации T01–T14 актуализированы и пройдены: transaction/concurrency, idempotent event, disable/restore/manager scope/handover/last-superadmin, сбои внешних эффектов видимы | IN_PROGRESS 2026-10-06: решения D1–D3 приняты; ветки: увольнение с датой и восстановление; передача дел в отпуске/больничном; exit-опрос ·11–13 и release scope; отдельные узкие feature ветки |
| PROD-15 | Синтетическая backup/restore проверка + runbook | Реальный dump/restore изолированной CI БД, сверка данных/связей/vault; DB proof не покрывает внешние файлы; инструкция передаётся DevOps; не читать production data | DONE (merged 2026-10-06, PR150) · [PR150](https://github.com/zapleoceo/sinhrm/pull/150),081d2a1:11 checks SUCCESS, Astra PASS;20 seed/22 restore assertions; production restore не заявлен |
| PROD-16 | Передача эксплуатационной приёмки DevOps | При переносе DevOps настраивает production backup/restore, секреты, alerts и реакцию; приложение передаёт проверяемую инструкцию и health | EXTERNAL_PENDING · ответственность DevOps; не блокирует самостоятельную разработку frontend/backend; вопросы RPO/RTO владельцу сняты · владелец 2026-10-06: выкладка и откат — зона DevOps, не наша забота |

## Раунд 4 — сквозная приёмка и внешние потоки

| ID | Задача | Критерий завершения | Состояние / зависимость |
|---|---|---|---|
| PROD-17 | API end-to-end выбранного пилотного workflow | Известный dataset, успешный сценарий, повторы, ошибки и forbidden для выбранного пути; hiring/оффер/найм/адаптация и exact reports по периоду/scope проверяются в объёме включённого scope; обнаруженные дефекты закрыты | DONE (merged 2026-10-06, PR151) · [PR151](https://github.com/zapleoceo/sinhrm/pull/151),4626e81:10 checks SUCCESS, Astra PASS; реальный HTTP путь заявки/вакансии/кандидата/решения/отчётов на синтетике; не полная live приёмка |
| PROD-18 | Матрица включённых модулей/ролей + UI | Проверки прав/ошибок/повторов/PII для каждого включённого модуля; desktop/mobile/light/dark, keyboard и реальные понятные сценарии; known skips перечислены | DONE (merged 2026-10-06, PR152) · протокол и HTTP module boundary [PR152](https://github.com/zapleoceo/sinhrm/pull/152),4bf3524:10 checks SUCCESS, Astra PASS; полная матрица и live UI ещё не приняты |
| PROD-19 | Google reconnect/send/read/calendar/sheets | Разрешённый владелец аккаунта подтвердил gmail.send; проверены выбранный поток, expiry/refresh/denial/retry и безопасные ошибки | EXTERNAL_PENDING · UI/код в138; подготовка протокола самостоятельная · владелец 2026-10-06: интегрируем всё, что возможно; каждый модуль включает пользователь/админ сам при необходимости |
| PROD-20 | Live AI usefulness/safety | Разрешённые синтетические задания и явные критерии полезности, отказа, стоимости/latency/human correction; no autonomous personnel decisions; провайдер проверен | EXTERNAL_PENDING · после10 и scope/data decisions |
| PROD-21 | Выбранные messaging/telephony потоки | API/договор/права, реальный обезличенный payload, send/read/webhook/retry/disable, дедуп и источник; каждый неподключённый provider отмечен отдельно | EXTERNAL_PENDING · выбрать сервисы; no fabricated live proof · владелец 2026-10-06: интегрируем всё, что возможно; каждый модуль включает пользователь/админ сам при необходимости |
| PROD-22 | Clipper live calibration | Все заявленные5 площадок проверены на разрешённых страницах, extraction/dedup/auth/denial, ограничения документированы | EXTERNAL_PENDING · страницы работодателя; fixtures остаются synthetic · владелец 2026-10-06: интегрируем всё, что возможно; каждый модуль включает пользователь/админ сам при необходимости |
| PROD-23 | Первый сегмент/интервью/метрики пилота | Роли/организация/ценность/явные не-желания, baseline и применимые к выбранному пилоту S-критерии, обезличенный протокол и пользовательская приёмка | OWNER_PENDING / EXTERNAL_PENDING · public reviews уже доступны, контакты и пилот ещё нет |

## Раунд 5 — подготовленные изменения и shipping

| ID | Задача | Критерий завершения | Состояние / зависимость |
|---|---|---|---|
| PROD-24 | Source138 reconnect | Новый shipping SHA/CI/Astra, содержательная live приёмка выбранного UI/flow | DONE (merged 2026-10-06, PR138) · [138](https://github.com/zapleoceo/sinhrm/pull/138),10/10 CI, Draft |
| PROD-25 | Source139 ranking | Scope применяется до max score, фильтра и сортировки; скрытая application не влияет на видимый score; ties/null/pagination; reviewed shipping SHA, live UI проверка | DONE (merged 2026-10-06, PR139) implementation · [139](https://github.com/zapleoceo/sinhrm/pull/139),2cdb496:10 checks SUCCESS; implementation cc7b9ff и объединённая совместимость проверены Astra; старый227046d не evidence готовности |
| PROD-26 | Source140 mobile mascot | Нет перекрытия changed content/confirmation на mobile; browser limitations перечислены | DONE (merged 2026-10-06, PR140) · [140](https://github.com/zapleoceo/sinhrm/pull/140),10/10 CI, Draft |
| PROD-27 | Source141 paginator | uk/ru/en labels/range, нулевые/пустые страницы, normal layout | DONE (merged 2026-10-06, PR141) · [141](https://github.com/zapleoceo/sinhrm/pull/141),10/10 CI, Draft |
| PROD-28 | Combined regression / validation docs | Выбранный shipping набор проверен совместно, конфликты/переводы/inventory не потеряны; lifecycle drafts остаются честно pending | DONE · 2026-10-06: сводные проверочные PR142/144/156 закрыты без мержа, их части влиты отдельными PR; конфликты слияния разрешены, эталоны e2e пересобраны под порядок проверки |
| PROD-29 | Release/rollback/live acceptance | Конкретный пакет, разрешение production, точные squash SHA и Web/API deploy IDs, stop condition, rollback owner, normal-URL smoke и изменённые сценарии | QUEUED · все применимые gates выше; main автоматически выкладывается · владелец 2026-10-06: release/rollback ведёт DevOps; с нашей стороны только готовый, проверенный main |

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
| PROD-37 | Согласовать draft UNIFIED-TZv0.3 | DONE 2026-10-06: v0.3 принят владельцем, слит в main вместе с актуализацией по текущему backlog |
| PROD-38 | Рабочие копии/артефакты | QUEUED: inventory выявил44 worktrees, включая активные и unmerged drafts; удаление требует подтверждённой ненужности и проверки ignored data, активные/evidence сохраняются |

## Обязательная подготовка приложения для Itstep

Источник нового требования — указание владельца от 05.10.2026: перенос на Itstep, сотрудники из СКУД, изучить связь как в Sintegrum. [Разбор и контракт поставки](itstep-integration.md) отделяет найденные механизмы от неизвестного протокола.

| ID | Задача | Критерий / состояние |
|---|---|---|
| PROD-39 | Пакет frontend/backend для DevOps Itstep | DONE (merged 2026-10-06, PR155) · [PR155](https://github.com/zapleoceo/sinhrm/pull/155),c7e638e:10 checks SUCCESS, Astra PASS; runtime/build/start/migrations/queue/scheduler/storage/health и границы текущего Vercel описаны; развёртывание на Itstep не выполнено |
| PROD-40 | Авторизация в целевой экосистеме | DEBT 2026-10-06 (владелец: записать в долги, позже возьмёт разработчик) · EXTERNAL_PENDING contract · текущий Sintegrum использует локальный пароль и opaque tokens, SSO этим не доказан. Владелец сообщил, что сервис/документацию пока дать не может; записать в долги, подготовить доступную часть кода; существующий login не заменять выдуманным SSO |
| PROD-41 | Импорт и сверка сотрудников СКУД | DONE (merged 2026-10-06, PR157) preparation · [PR157](https://github.com/zapleoceo/sinhrm/pull/157),f006c72:10 checks SUCCESS (CI37292425236), независимый Astra PASS. Typed gateway, явная схема/namespace, read-only preview/conflicts, проверки порядка дубликатов и race error states готовы. Production подключение отсутствует, сотрудники/права не меняются. Владелец предоставит контракт сервиса позже; live import EXTERNAL_PENDING |
| PROD-42 | Связь аккаунтов и права импортированных сотрудников | QUEUED: source-scoped IDs, уникальность и reviewed conflicts; email не выдаёт ACL; владелец superadmin подтверждён, массовое назначение прав остальным не определено. Подготовить конкретный безопасный сценарий после40/41 |
| PROD-43 | Проектные правила экономии контекста | DONE · [PR154](https://github.com/zapleoceo/sinhrm/pull/154),00682bd:10 checks SUCCESS, Astra PASS; merged main38d90ea. Короткий AGENTS.md, узкие задачи Luna, дисковый state, краткие логи и exact-head review; новые shared rules master_v3b269a264 и sinhrm adapter прочитаны, автоматическое разрешение release не добавлено |
| PROD-44 | Мобильная страница пользователей: горизонтальное переполнение | DONE (merged 2026-10-06, PR158) · [PR158](https://github.com/zapleoceo/sinhrm/pull/158),ccb3f52:10 checks SUCCESS (CI37297027767). Причина подтверждена изоляцией table/table-scroll/panel; paint containment сохраняет горизонтальную прокрутку таблицы и устраняет page overflow. Astra static и реальные 375/390 light/dark renders PASS; проверены полная видимость правого действия, меню роли/филиала и восстановление прокрутки. Desktop renders и combined156 требуют финальной приёмки; inherited mascot/paginator исправления принимаются совместно с140/141 |
| PROD-45 | MySQL: этап 1 — двойная поддержка PostgreSQL/MySQL | IN_REVIEW (2026-10-08, [PR170](https://github.com/zapleoceo/sinhrm/pull/170), включает draft PR169; CI 37733086728: tests + tests-mysql SUCCESS) · [ADR 0010](../adr/0010-mysql-dual-support.md), `Core\Support\Database\Sql`, соединение `mysql` (utf8mb4, `utf8mb4_0900_ai_ci`, strict, UTC), необязательный CI job `tests-mysql` (MySQL 8.4, полный PHPUnit). Прод не переключается |
| PROD-46 | Перенос БД на MySQL | IN_PROGRESS (решение владельца 2026-10-08: DevOps IT STEP требуют MySQL 8.4). Этапы: PROD-45 код → PROD-47 данные → PROD-48 бэкап → переключение DevOps → PROD-49 удаление PostgreSQL. Готово, когда прод работает на MySQL IT STEP, данные сверены, откат отрепетирован |
| PROD-47 | MySQL: перенос данных Neon → MySQL | QUEUED: preflight коллизий уникальных ключей под `utf8mb4_0900_ai_ci` (регистр/диакритика), дат за 2038, JSON; перенос с тем же `APP_KEY`; сверка строк/FK/auto_increment/SHA-256 вложений; репетиция отката. Основа — синтетический `scripts/mysql-transfer-proof.php` (PR169) |
| PROD-48 | MySQL: бэкап и восстановление (`mysqldump`) | QUEUED: `mysqldump --single-transaction --routines --hex-blob` + восстановление в изолированную БД, аналог `backup-restore.yml` для MySQL, обновление [backup-restore.md](../guides/backup-restore.md) |
| PROD-49 | Удалить поддержку PostgreSQL после переезда | BLOCKED by PROD-46: убрать Neon/pgsql ветки (`NeonConnectionConfig`, ветки миграций, `Sql`-pgsql), `tests-mysql` сделать обязательным вместо `tests` |
| PROD-50 | MySQL: индекс касаний по `meta.thread` | QUEUED: на PostgreSQL есть выражный индекс `touchpoints (channel, (meta->>'thread'))`, на MySQL — нет (функциональный индекс или генерируемая колонка); производительность, не корректность |

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

2026-10-05: main38d90ea после docs-only154; feature merge/deploy не выполнялись.145/146/148/149/150/151/152 и финальные139/153 имеют зелёный exact-head CI;155 CI/Astra PASS.157f006 preparation-only имеет10 checks SUCCESS и Astra PASS; контракт сервиса и live acceptance остаются долгом, владелец предоставит данные позже.158ccb3 устраняет mobile page overflow,10 checks SUCCESS, Astra static/mobile renders PASS; desktop/final verdict ожидается. Расширенное156c396 проходит новый CI/restore/Astra; прежний PASS156d23 не означает приёмку нового head. Инфраструктуру настраивают DevOps; production readiness OPEN.

2026-10-06: все 17 готовых PR (138–141, 143, 145–153, 155, 157, 158) и пять обновлений зависимостей влиты в main по прямому разрешению владельца; сводные проверочные PR142/144/156 закрыты. Остаются владельческие решения D1–D3 (PROD-11…13), внешние потоки и live acceptance (PROD-19…23, 40), каталог TZ-хвостов (PROD-30…38) и release/rollback (PROD-29). Деплой: Web на SHA сборки из `/build.json`, API на SHA из `/api/health`; бэкенд не менялся после PR150 кроме тестов.

### Решения владельца 2026-10-06

1. Человек может быть и Employee, и User; admin может быть без Employee; несколько ролей на одну учётку, переключатель роли в меню аватарки.
2. Увольнение с даты, которую указывает менеджер; уволенного можно восстановить в прежней или новой должности.
3. Передача дел (необязательное поле) при увольнении, заявке на отпуск и заявке на больничный; exit-опрос при увольнении нужен.
4. Авторизация и сервис сотрудников Itstep: записано в долги, разработчик возьмёт позже.
5. Интеграции: интегрируем всё, что можно; пользователь сам включает нужный ему модуль.
6. ТЗ v0.3 принято.
7. Выкладка и откат — зона DevOps, не наша забота.
