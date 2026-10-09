# Архитектура

## Простыми словами
Система из трёх частей: **экран** (то, что видит пользователь в браузере), **сервер** (правила и проверки)
и **база данных** (где всё хранится). Экран никогда не ходит в базу напрямую — только через сервер.

## Техническая схема
```
Браузер ──► SPA (Angular, статика; frontend/dist/frontend/browser)
              │  /api/* и /sanctum/* — на том же внешнем origin (rewrite/reverse proxy → cookie-сессия работает)
              ▼
           Laravel 13 API (PHP 8.4, backend/public/index.php)
              │
              ▼
           MySQL 8.4 (IT STEP) — данные, сессии, очередь задач, зашифрованные секреты
           (ADR 0010; развёртывание БД — guides/deploy-mysql.md)
Chrome «SinHRM Clipper» ──► <origin>/api/clipper/* (Bearer-токен, только эти маршруты)
GitHub Actions ──► CI на каждый PR (обязательные: backend, frontend, extension, security, docs, worklog) ─► cron (30 мин): POST /api/ops/jobs/run
```

| Решение | Почему |
|---|---|
| Один домен для фронта и API (rewrite / reverse proxy) | Sanctum SPA-режим держится на cookie одного origin ([itstep-app-handoff.md](../guides/itstep-app-handoff.md)) |
| Сессии, кэш, очередь — в БД приложения | отдельные Redis и постоянный диск приложению не нужны |
| Единственная БД — MySQL 8.4 | DevOps IT STEP разворачивают MySQL ([ADR 0010](../adr/0010-mysql.md)); развёртывание — [deploy-mysql.md](../guides/deploy-mysql.md); CI-страж `scripts/mysql-only-guard.mjs` |
| Фоновые задачи по расписанию (`cron.yml` или планировщик DevOps, каждые 30 мин) | прикладных `ShouldQueue`-обработчиков нет, задачи — `Core\Contracts\ScheduledJob`, запуск — `POST /api/ops/jobs/run` |
| Выкладка — pipeline DevOps IT STEP | GitHub Actions только проверяют (CI) — [deploy.md](../guides/deploy.md), размещение — [itstep-app-handoff.md](../guides/itstep-app-handoff.md) |

## Бэкенд: модули
Код разбит по доменам в `backend/app/Modules/<Имя>`. Модуль содержит всё своё:
`Providers/` (регистрация), `routes.php` (маршруты под `/api`), `Http/Controllers` (только оркестрация),
`Http/Requests` (валидация), `Services` (логика), `Repositories` (запросы к БД), `Database/Migrations`, `Contracts` (интерфейсы).
Базовый класс `ModuleServiceProvider` сам подключает маршруты и миграции модуля — новый модуль добавляется одной строкой
в `bootstrap/providers.php`.

| Модуль | Статус | Документация |
|---|---|---|
| Core (health, общие механизмы) | ✅ | [modules/core.md](../modules/core.md) |
| Auth (вход через Google, роли) | ✅ | [modules/auth.md](../modules/auth.md) |
| Users (админка пользователей) | ✅ | [modules/users.md](../modules/users.md) |
| Shell (оболочка фронтенда) | ✅ | [modules/shell.md](../modules/shell.md) |
| Integrations (секреты и внешние сервисы) | ✅ | [modules/integrations.md](../modules/integrations.md) |
| Ai (AI Broker: ворота, дневные лимиты, `ai_requests`, отложенные ответы `ai.poll`, промпты-классы; функции живут в Scripts, MailAgent, Recruiting) | ✅ | [modules/ai.md](../modules/ai.md) |
| Directory (справочники, филиалы пользователей) | ✅ | [modules/directory.md](../modules/directory.md) |
| Recruiting (вакансии, воронки, кандидаты, касания, «Вхідні», отчёты, каналы привлечения с UTM — [acquisition-channels.md](../modules/acquisition-channels.md)) | ✅ | [modules/recruiting.md](../modules/recruiting.md) |
| Scripts (версии скриптов, оценка касаний, шаблоны, задачи-напоминания) | ✅ | [modules/scripts.md](../modules/scripts.md) |
| Overview (главная страница — дашборд) | ✅ | [modules/overview.md](../modules/overview.md) |
| GoogleWorkspace (OAuth-подключение Gmail/Calendar/Sheets, встречи, импорт из таблиц) | ✅ | [modules/google-workspace.md](../modules/google-workspace.md) |
| MailAgent (разбор Gmail: отклики → кандидаты и задачи, письма кандидатов → касания) | ✅ | [modules/mail-agent.md](../modules/mail-agent.md) |
| People (сотрудники, оргструктура, самообслуживание, найм из Recruiting) | ✅ | [modules/people.md](../modules/people.md) |
| TimeOff (отпуска: типы, политики, праздники, баланс-журнал, запросы, календарь, начисление) | ✅ | [modules/timeoff.md](../modules/timeoff.md) |
| Documents (шаблоны документов, документы сотрудников, файлы в БД, «Ознайомлений»; КЕП — заготовка) | ✅ | [modules/documents.md](../modules/documents.md) |
| Workflows (онбординг/офбординг: шаблоны, снимки запусков, исполнители шагов, триггеры People, `workflows.tick`) | ✅ | [modules/workflows.md](../modules/workflows.md) |
| Perform (1:1, цели OKR, KPI, фидбек, оценка 360 по компетенциям, планы развития; доступ по модели People) | ✅ | [modules/perform.md](../modules/perform.md) |
| Pulse (опросы с волнами и расписанием, анонимность с порогом группы, eNPS, сравнение волн, опросы жизненного цикла, настроение, `pulse.tick`) | ✅ | [modules/pulse.md](../modules/pulse.md) |
| Desk (обращения в HR: категории с SLA, внутренние заметки, файлы, `desk.sla`) | ✅ | [modules/desk.md](../modules/desk.md) |
| SafeSpeak (анонимные сообщения: код доступа, без пользователя/IP/времени, обработчики по флагу) | ✅ | [modules/safe-speak.md](../modules/safe-speak.md) |
| Knowledge (база знаний: Markdown → очищенный HTML, аудитория, поиск, версии, голоса) | ✅ | [modules/knowledge.md](../modules/knowledge.md) |
| Assets (активы, история выдач, шаг воркфлоу `collect_assets`) | ✅ | [modules/assets.md](../modules/assets.md) |
| HiringRequests (заявки на подбор: маршрут согласования с SLA, настраиваемая форма, автосоздание вакансии, `hiring.sla`) | ✅ | [modules/hiring-requests.md](../modules/hiring-requests.md) |
| Time (табели по неделям, сверхурочные по графику, отпуска TimeOff как отсутствие, согласование, `time.reminders`) | ✅ | [modules/time.md](../modules/time.md) |
| Reports (каталог отчётов по всем модулям, конструктор по белому списку, CSV) | ✅ | [modules/reports.md](../modules/reports.md) |
| Extension (браузерное расширение `extension/`: кандидат с открытой страницы профиля; API — в Recruiting, токен только для `/api/clipper/*`) | ✅ код, установка вручную | [modules/extension.md](../modules/extension.md) |
| Channels (вебхуки мессенджеров и телефонии → лента кандидата, отправка из карточки, демо-события) | ✅ код, включается токенами | [modules/channels.md](../modules/channels.md) |
| Privacy (персональные данные: выгрузка, обезличивание, журнал запросов, срок хранения) | ✅ | [modules/privacy.md](../modules/privacy.md) |
| Audit (журнал изменений, вкладка «История» сотрудника и кандидата) | ✅ | [modules/audit.md](../modules/audit.md) |
| Observability (журнал ошибок) | ✅ | [modules/observability.md](../modules/observability.md) |
| Assistant (чат-ассистент и MCP) | ✅ | [modules/assistant.md](../modules/assistant.md) |

### Границы модулей
Модуль обращается к другому модулю через его `Contracts` (и `DTO`, `Enums`, `Events`), а не импортирует напрямую его
`Models`, `Services`, `Repositories` или `Http`. Core — общее ядро (`Core\Support`, `Core\Http`, `Core\Contracts`), его импортируют
все. Правило проверяет тест `backend/tests/Unit/Core/ModuleBoundariesTest.php` (сканирует `use` в `app/Modules`):
- исключение для всех — `Auth\Http\Middleware\EnsureUserIsActive` (под ним маршруты каждого модуля);
- текущие нарушения (на 2026-10-08 — 186 импортов в 128 файлах: чужие `Models` 155, `Services` 18, `Http` 11, `Repositories` 2)
  записаны в `backend/tests/Unit/Core/module-boundaries-baseline.php`; новое нарушение валит тест, а исправленное надо
  удалить из списка (тест подскажет) — список только сокращается;
- двусторонние зависимости модулей (7 пар: Audit ↔ People, Audit ↔ Recruiting, Auth ↔ Core, Channels ↔ Recruiting,
  Core ↔ Pulse, Core ↔ Recruiting, Recruiting ↔ Scripts) записаны в `KNOWN_CYCLES` теста по тому же принципу.
Разрывать циклы и выносить зависимости в контракты — отдельными PR по модулю.
Сервисы, которые чаще всего нужны другим модулям, уже закрыты узкими контрактами модуля-владельца (реализация —
сам сервис, биндинг — его провайдер): `People\Contracts\PeopleAccess`, `EmployeeLookup`; `Scripts\Contracts\TaskScheduler`,
`TaskReader`; `Ai\Contracts\AiGateway` (реализует только `AiService`); `Recruiting\Contracts\RecruitingAccess`,
`CandidateIntake`, `TouchpointLogger`; `GoogleWorkspace\Contracts\GoogleConnections`; `Integrations\Contracts\IntegrationConfigs`,
`IntegrationSettings`; `Audit\Contracts\AuditHistory`. Импорты чужих `Models` в связях Eloquent (`belongsTo`/`hasMany`
требуют класс модели) оставлены: их вынос — отказ от связей Eloquent, отдельное архитектурное решение (ADR).

## Фронтенд
`frontend/src/app/core` — общие сервисы (API, auth, i18n), `features/<имя>` — экраны, загружаются лениво.
Standalone-компоненты, signals, `OnPush`, без `any`. Дизайн — [design-direction.md](design-direction.md).

## Ограничения (осознанные)
>>>

- Фоновые задачи выполняются с задержкой до ~30 мин (частота cron): модули регистрируют `Core\Contracts\ScheduledJob`,
  cron вызывает `POST /api/ops/jobs/run` ([core.md](../modules/core.md)).
- Шаги воркфлоу выполняются тем же cron (`workflows.tick`, до 50 шагов за вызов); вебхуки воркфлоу — синхронно в нём,
  с таймаутом 10 с и SSRF-защитой ([workflows.md](../modules/workflows.md)).
- Опросы и настроение (`pulse.tick` тем же cron): открытие/закрытие волн, следующая волна расписания, опросы 30/90 дней
  и уведомления о падении настроения — с задержкой до ~30 мин. Анонимность обеспечивает сервер: в ответах анонимных
  волн нет id сотрудника и времени, соль хэша стирается при закрытии, группы меньше минимума не показываются
  ([pulse.md](../modules/pulse.md)).
- Файлы документов до 2 МБ хранятся в БД (base64 в `longText`) за интерфейсом `DocumentStorage` — до выбора объектного
  хранилища ([documents.md](../modules/documents.md)).
- Анонимная сторона Safe Speak (`/api/safe-speak/public/*`) подключена вне групп `api`/`web`: без сессии, Sanctum и
  CSRF, лимиты — по HMAC-хэшу адреса в кэше ([safe-speak.md](../modules/safe-speak.md)).
- Отчёты считаются на лету в запросе (без хранилища/материализации): группировки по месяцам и корзинам — в PHP
  (не зависит от SQL-диалекта), конструктор — до 5000 строк, CSV — потоком ([reports.md](../modules/reports.md)).
- Заявки на подбор и табели (`hiring.sla`, `time.reminders` тем же cron): уведомления согласующим — задачи при активации
  шага (сразу), эскалация просрочки SLA и автозакрытие заявок, пятничные напоминания о табеле — с задержкой до ~30 мин
  ([hiring-requests.md](../modules/hiring-requests.md), [time.md](../modules/time.md)).
- Работа «после ответа» (оценка разговора по скрипту) — `dispatchAfterResponse()` в том же запросе, без очереди.
- Постоянные соединения (Telegram userbot, WebSocket) невозможны — только вебхуки: Telegram Business, WhatsApp Cloud, Viber и
  телефония присылают события на `POST /api/webhooks/{key}` ([channels.md](../modules/channels.md)); все каналы пишут касания
  через один `TouchpointIngestor`.
- Google (Gmail, Calendar v3, Sheets v4, OAuth token endpoint) вызывается REST-запросами Laravel HTTP-клиента, без
  `google/apiclient` (слишком тяжёл для serverless-бандла). Почта читается опросом раз в 30 мин (cron `mail.sync`), без
  push-уведомлений Gmail (Pub/Sub) — [mail-agent.md](../modules/mail-agent.md).
