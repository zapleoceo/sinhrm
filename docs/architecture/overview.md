# Архитектура

## Простыми словами
Система из трёх частей: **экран** (то, что видит пользователь в браузере), **сервер** (правила и проверки)
и **база данных** (где всё хранится). Экран никогда не ходит в базу напрямую — только через сервер.

## Техническая схема
```
Браузер ──► sinhrm.vercel.app (Angular SPA, Vercel)
              │  /api/*  (Vercel rewrite, тот же домен → cookie-сессия работает)
              ▼
           sinhrm-api.vercel.app (Laravel 13, runtime vercel-php, PHP 8.5, serverless, регион fra1 — рядом с БД)
              │
              ▼
           Neon Postgres (Frankfurt) — данные, сессии, очередь задач, зашифрованные секреты
Chrome «SinHRM Clipper» ──► sinhrm.vercel.app/api/clipper/* (Bearer-токен, только эти маршруты)
GitHub Actions ──► тесты на каждый PR ─► деплой на Vercel ─► cron (30 мин): POST /api/ops/jobs/run
```

| Решение | Почему |
|---|---|
| Один домен для фронта и API (rewrite) | `vercel.app` — публичный суффикс, cookie между двумя `*.vercel.app` не работают |
| Сессии, кэш, очередь — в Postgres | у serverless нет постоянного диска и процессов |
| Фоновые задачи через cron GitHub Actions | у vercel-php нет воркеров; Vercel Hobby cron — 1 раз в сутки |
| Деплой из GitHub Actions (Vercel CLI) | деплой только после зелёных тестов; аккаунт Vercel не привязан к GitHub |

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
| Directory (справочники, филиалы пользователей, импорт из Sintegrum) | ✅ | [modules/directory.md](../modules/directory.md) |
| Recruiting (вакансии, воронки, кандидаты, касания, «Вхідні», отчёты) | ✅ | [modules/recruiting.md](../modules/recruiting.md) |
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
| Extension (браузерное расширение `extension/`: кандидат с открытой страницы профиля; API — в Recruiting, токен только для `/api/clipper/*`) | ✅ код, установка вручную | [modules/extension.md](../modules/extension.md) |
| Channels (вебхуки мессенджеров и телефонии → лента кандидата, отправка из карточки, демо-события) | ✅ код, включается токенами | [modules/channels.md](../modules/channels.md) |

## Фронтенд
`frontend/src/app/core` — общие сервисы (API, auth, i18n), `features/<имя>` — экраны, загружаются лениво.
Standalone-компоненты, signals, `OnPush`, без `any`. Дизайн — [design-direction.md](design-direction.md).

## Ограничения (осознанные)
- Холодный старт API ~0.3–1 с после простоя.
- Фоновые задачи выполняются с задержкой до ~30 мин (частота cron): модули регистрируют `Core\Contracts\ScheduledJob`,
  cron вызывает `POST /api/ops/jobs/run` ([core.md](../modules/core.md)).
- Шаги воркфлоу выполняются тем же cron (`workflows.tick`, до 50 шагов за вызов); вебхуки воркфлоу — синхронно в нём,
  с таймаутом 10 с и SSRF-защитой ([workflows.md](../modules/workflows.md)).
- Опросы и настроение (`pulse.tick` тем же cron): открытие/закрытие волн, следующая волна расписания, опросы 30/90 дней
  и уведомления о падении настроения — с задержкой до ~30 мин. Анонимность обеспечивает сервер: в ответах анонимных
  волн нет id сотрудника и времени, соль хэша стирается при закрытии, группы меньше минимума не показываются
  ([pulse.md](../modules/pulse.md)).
- Файлы документов до 2 МБ хранятся в Postgres (base64) за интерфейсом `DocumentStorage` — до выбора объектного
  хранилища ([documents.md](../modules/documents.md)).
- Работа «после ответа» (оценка разговора по скрипту) — `dispatchAfterResponse()` в том же запросе, без очереди.
- Постоянные соединения (Telegram userbot, WebSocket) невозможны — только вебхуки: Telegram Business, WhatsApp Cloud, Viber и
  телефония присылают события на `POST /api/webhooks/{key}` ([channels.md](../modules/channels.md)); все каналы пишут касания
  через один `TouchpointIngestor`.
- Google (Gmail, Calendar v3, Sheets v4, OAuth token endpoint) вызывается REST-запросами Laravel HTTP-клиента, без
  `google/apiclient` (слишком тяжёл для serverless-бандла). Почта читается опросом раз в 30 мин (cron `mail.sync`), без
  push-уведомлений Gmail (Pub/Sub) — [mail-agent.md](../modules/mail-agent.md).
