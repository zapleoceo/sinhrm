# Передача проекта: чеклист

## Простыми словами
Страница для того, кто принимает SinHRM: где что лежит, как проверить изменения, как выложить на MySQL 8.4, какие
секреты нужны и откуда их взять, кто за что отвечает и что открыто. Значения секретов здесь не пишутся — перечислены
имена и место хранения. Состояние сверено с `main` 6135d4ec (2026-10-08).

## 1. Что где лежит

| Путь | Что там | Подробнее |
|---|---|---|
| `backend/` | Laravel 13 API, PHP 8.4 в CI (`composer.json`: `^8.3`), модули `app/Modules/<Name>` | [architecture/overview.md](../architecture/overview.md) |
| `backend/app/Modules/<Name>/routes.php` | маршруты модуля (`/api/...`); публичные — `routes.public.php` | [api.md](api.md) |
| `backend/.env.example` | все ключи окружения API с плейсхолдерами | [secrets.md](../architecture/secrets.md) |
| `backend/vercel.json`, `backend/api/index.php` | запуск API на Vercel (`vercel-php`) | [deploy.md](deploy.md) |
| `frontend/` | Angular 22 SPA: `src/app/core` (общее) + `src/app/features/<name>` (фичи), строки ru/uk/en | [core.md](../modules/core.md), [shell.md](../modules/shell.md) |
| `frontend/scripts/build-docs.mjs` | сборка справки «Довідка» из `docs/` в `public/help/docs.json` | [shell.md](../modules/shell.md) |
| `frontend/e2e/` | UI parity: инвентарь, снапшоты, axe на mock API | [ui-parity.md](ui-parity.md) |
| `extension/` | Chrome-расширение «SinHRM Clipper» (MV3, TypeScript, esbuild, Vitest) | [extension.md](../modules/extension.md) |
| `rest/<модуль>/*.http` | примеры запросов к API (`rest/http-client.env.json` — окружения без секретов) | [api.md](api.md) |
| `scripts/` | node-проверки CI: документация и тесты вместе с кодом, журнал, MySQL-страж, мёртвые ссылки, штамп сборки | [development.md](development.md#что-проверяет-ci-в-job-docs) |
| `.github/workflows/` | `ci.yml`, `deploy.yml`, `cron.yml`, `demo-fill.yml`, `night-window.yml`, `mysql-data-transfer.yml` | [deploy.md](deploy.md#как-устроено) |
| `docs/modules/` | страница на каждый модуль: «Что это и зачем» → «Как пользоваться» → «Как устроено» → «Как проверить» | [modules/README.md](../modules/README.md) |
| `docs/guides/`, `docs/architecture/`, `docs/adr/` | правила, деплой, переезд, восстановление; архитектура и секреты; решения | [docs/README.md](../README.md) |
| `docs/product/` | единое ТЗ, свидетельства аудитории, production backlog, интеграция с Itstep | [production-backlog.md](../product/production-backlog.md) |
| `docs/security/audit-2026-10.md` | аудит безопасности октября 2026 и оставшиеся риски | [audit-2026-10.md](../security/audit-2026-10.md) |
| `docs/worklog.d/` | журнал работ: один файл на PR | [worklog.d/README.md](../worklog.d/README.md) |
| `docs/tasks/` | состояние долгих задач (`## Стан`) | — |

Модули бэкенда (29): Ai, Assets, Assistant, Audit, Auth, Channels, Core, Desk, Directory, Documents, GoogleWorkspace,
HiringRequests, Integrations, Knowledge, MailAgent, Observability, Overview, People, Perform, Privacy, Pulse, Recruiting,
Reports, SafeSpeak, Scripts, Time, TimeOff, Users, Workflows. Назначение и статус каждого — [modules/README.md](../modules/README.md).
Имя страницы документации — имя модуля в kebab-case ([development.md](development.md#документация)).

Решения: [ADR 0001–0009](../adr/), [ADR 0010](../adr/0010-mysql-dual-support.md) заменён
[ADR 0011 — MySQL 8.4 единственная СУБД](../adr/0011-mysql-only.md).

## 2. Как проверить изменения

Эталон проверки — GitHub Actions (`ci.yml`, workflow `CI`); локально запускаем lint и точечные тесты изменённых файлов
([development.md](development.md#тесты-что-локально-что-в-ci)).

| Часть | Команды (как в CI) | Job |
|---|---|---|
| Бэкенд | `composer install` → `vendor/bin/pint --test` → `vendor/bin/phpstan analyse --memory-limit=1G` → `cp .env.example .env && php artisan key:generate` → `php artisan test --coverage --min=70` (MySQL 8.4, `DB_CONNECTION=mysql`) | `lint`, `tests`, агрегатор `backend` |
| OpenAPI | `php artisan migrate --force` → `php artisan scramble:export --path=openapi.json` → артефакт `openapi` | `api-docs` (в агрегаторе `backend`) |
| Фронтенд | `npm ci` → `npx ng lint` → `npx ng test --watch=false --coverage` → `npm run test:docs` → `npm run build` | `frontend` |
| UI parity | `npm run e2e:lint` → `npm run build` → `npm run e2e` (снапшоты — `npm run e2e:update`) | `ui-parity`, необязательный |
| Расширение | `npm ci` → `npm run lint` → `npm run typecheck` → `npm test` → `npm run package` → артефакт `sinhrm-clipper` | `extension` |
| Безопасность | gitleaks по всей истории, `composer audit --locked`, `npm audit --audit-level=high` | `security` |
| Документация | `node --test scripts/pr-checks.test.mjs`, `node --test scripts/docs-links-check.test.mjs && node scripts/docs-links-check.mjs`, `node scripts/docs-check.mjs origin/main`, `node scripts/tests-check.mjs origin/main` | `docs` |
| Журнал | `node --test scripts/worklog.test.mjs`, `node scripts/worklog-check.mjs origin/main` | `worklog` |
| MySQL-страж | `node --test scripts/mysql-only-guard.test.mjs && node scripts/mysql-only-guard.mjs` | шаг `lint` |

Ручной запуск на машине разработчика: API — `php artisan migrate` и `php artisan serve` (порт 8000) на MySQL 8.4
(`DB_URL=mysql://<user>:<password>@127.0.0.1:3306/<db>`); SPA — `npm start` в `frontend/` (порт 4200). SPA обращается
к `/api/*` относительным адресом, proxy-конфига для `ng serve` в репозитории нет — его добавляет тот, кому нужен
локальный стенд. Вход Google работает на prod-домене ([development.md](development.md#проверка-входа-и-сессий)).

Ночная проверка дат: `night-window.yml` (понедельник 05:17 UTC и вручную) — [development.md](development.md#ночное-окно-utc-против-киева).

## 3. Выкладка на MySQL 8.4

| Шаг | Где описано |
|---|---|
| Требования к runtime, сборке, миграциям, очереди, расписанию, файлам, health для DevOps Itstep | [itstep-app-handoff.md](itstep-app-handoff.md) |
| Текущий Vercel-контур, заморозка автовыкладки (`VERCEL_DEPLOY_ENABLED`), ручной хотфикс замороженного релиза | [deploy.md](deploy.md#заморозка-vercel) |
| Перенос данных `php artisan db:transfer-to-mysql`, порядок переключения, откат, удаление инструмента после переезда | [mysql-cutover.md](mysql-cutover.md) |
| Резервирование и проверка восстановления (MySQL-доказательство — draft PR #174, PROD-48) | [backup-restore.md](backup-restore.md) |
| Приёмка пилота | [pilot-acceptance.md](pilot-acceptance.md) |

Замороженный боевой релиз — ветка и коммит указаны в [mysql-cutover.md](mysql-cutover.md#замороженный-боевой-релиз-до-cutover) (коммит `8875ac4e`). Миграции на целевой площадке
запускает API: `POST /api/ops/migrate` с заголовком `X-Ops-Secret` (или `php artisan migrate --force` на сервере).
Фоновые задачи — `POST /api/ops/jobs/run` каждые 30 минут (`cron.yml`; на площадке Itstep — их планировщик,
[ADR 0006](../adr/0006-cron-via-github-actions.md)). Проверка после выкладки — `GET /api/health` (поле `version` = SHA
API) и `/build.json` SPA ([deploy.md](deploy.md#идентификатор-реально-собранной-ревизии)).

## 4. Секреты: какие и откуда брать

Значения не хранятся в репозитории; правила — [secrets.md](../architecture/secrets.md).

| Имя | Где хранится | Кто выдаёт |
|---|---|---|
| `DB_URL` (или `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`), `MYSQL_ATTR_SSL_CA` | окружение API (Vercel env / площадка Itstep) | DevOps (сервер MySQL 8.4) |
| `APP_KEY` (+ `APP_PREVIOUS_KEYS` после ротации) | окружение API; при переезде — **тот же** ключ ([mysql-cutover.md](mysql-cutover.md)) | владелец текущего Vercel-проекта |
| `SUPERADMIN_EMAIL` | окружение API | владелец продукта |
| `OPS_SECRET` | окружение API и секрет GitHub Actions с тем же значением | владелец репозитория |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` (OAuth-клиент входа), `GOOGLE_REDIRECT_URI`, `GOOGLE_CONNECT_REDIRECT_URI` | окружение API; redirect URI регистрируются в Google Cloud | владелец Google Cloud-проекта |
| Токены интеграций (AI Broker, Google Workspace OAuth, Telegram, WhatsApp, Viber, телефония и др.) | таблица `integration_secrets` (шифрование `APP_KEY`), ввод — «Адміністрування → Інтеграції» | суперадмин в интерфейсе ([integrations.md](../modules/integrations.md)) |
| GitHub Actions secrets: `VERCEL_TOKEN`, `VERCEL_ORG_ID`, `VERCEL_PROJECT_ID_API`, `VERCEL_PROJECT_ID_WEB`, `OPS_SECRET` | Settings → Secrets and variables → Actions | владелец репозитория (zapleoceo) |
| Переменная репозитория `VERCEL_DEPLOY_ENABLED` (не секрет) | Settings → Secrets and variables → Actions → Variables | владелец репозитория |
| `TRANSFER_SOURCE_URL`, `TRANSFER_TARGET_URL` | переменные оболочки на время разового переноса | DevOps в окне переезда ([mysql-cutover.md](mysql-cutover.md)) |

## 5. Процессы и владельцы

- **Ветки и PR:** одна задача — одна ветка от `main` (`feat/`, `fix/`, `docs/`, `chore/`), PR по шаблону
  `.github/pull_request_template.md` с разделом «Доказательство»; в `main` не пушим; merge — squash
  ([development.md](development.md#процесс-обязателен), [CLAUDE.md](../../CLAUDE.md)).
- **Ревью:** независимый агент Astra проверяет локальный и GitHub diff (SOLID, DRY, модульность, безопасность, тесты,
  документация); merge разрешает владелец. Ruleset «Protect main»: запрет удаления и force-push, PR обязателен.
- **Обязательные проверки** (ruleset «Protect main»): `backend`, `frontend`, `extension`, `security`, `docs`, `worklog`.
  Имена jobs не переименовываем ([deploy.md](deploy.md#раскладка-ci-параллельные-job-и-обязательные-проверки)).
- **Метки:** `no-worklog` — PR без записи журнала (правки без изменения поведения), `no-tests-needed` — исключение
  проверки тестов (ставит ревьюер), `preview` — preview-деплой Vercel (при включённой автовыкладке), `DO NOT MERGE` —
  проверочный PR. После установки метки — Re-run job.
- **Документация вместе с кодом:** правка модуля требует содержательной правки его `docs/modules/<модуль>.md` и теста
  того же модуля; каждый PR добавляет `docs/worklog.d/<YYYY-MM-DD>-<slug>.md`.
- **Зависимости:** dependabot (`.github/dependabot.yml`), Actions закреплены по SHA.
- **Владельцы:** продукт и решения — владелец (суперадмин); репозиторий и секреты GitHub — zapleoceo; инфраструктура
  Itstep, бэкапы, alerts, выкладка и откат на целевой площадке — DevOps Itstep
  ([production-backlog.md](../product/production-backlog.md)).

## 6. Что открыто

**Production backlog** ([production-backlog.md](../product/production-backlog.md)):

| ID | Что | Состояние |
|---|---|---|
| PROD-46, PROD-47 | Переезд на MySQL Itstep: репетиция переноса на копии прода и боевой перенос в окно DevOps | IN_PROGRESS |
| PROD-48 | MySQL-бэкап и изолированное восстановление | IN_PROGRESS, draft PR #174 |
| PROD-51 | Удаление инструмента переезда после cutover | QUEUED |
| PROD-14 | Employee↔User lifecycle: прогон T01–T14 при включении автоматизации | IN_PROGRESS |
| PROD-16, PROD-19–23, PROD-32, PROD-35, PROD-40 | Эксплуатация у DevOps, живые внешние потоки (Google, AI, мессенджеры, Clipper), пилот, авторизация Itstep | EXTERNAL_PENDING / OWNER_PENDING |
| PROD-29 | Release/rollback/live acceptance | QUEUED |
| PROD-30, 31, 34, 36, 38, 42 | Продуктовые хвосты ТЗ, зависимости модулей, рабочие копии, права импортированных сотрудников | QUEUED / OWNER_PENDING |

**Оставшиеся риски безопасности** R1–R9 ([audit-2026-10.md](../security/audit-2026-10.md#оставшиеся-риски-и-владелец-решения)):
права пользователя MySQL (DDL у приложения), TLS к MySQL, preview с секретами Vercel, `style-src 'unsafe-inline'`,
токен расширения в `chrome.storage.local`, старые токены без префикса `sinhrm_`, вложения base64 в БД, custom pattern
secret scanning для `sinhrm_`, break-glass единственного суперадмина.

**Открытые PR на 2026-10-08** (`gh pr list`): draft #174 (PROD-48), #197 и #198 (Angular 22.2.1, larastan 3.12.3),
dependabot #184–#194.

**Известный хвост:** `scripts/backup-restore-proof.test.mjs` ссылается на удалённый `backup-restore.yml` и в CI не
запускается; его судьбу решает PR #174 (список `ALLOWED` в `scripts/mysql-only-guard.mjs`).
