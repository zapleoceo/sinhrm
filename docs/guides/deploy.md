# Деплой

## Простыми словами
Боевой контур — инфраструктура IT STEP: Laravel API и Angular SPA под одним HTTPS-адресом, база — MySQL 8.4
([ADR 0010](../adr/0010-mysql.md)). Сборку и выкладку делает pipeline DevOps IT STEP; репозиторий описывает, **что** собрать
и как проверить: требования приложения — [itstep-app-handoff.md](itstep-app-handoff.md), база данных, учётные записи,
миграции, TLS и cron — [deploy-mysql.md](deploy-mysql.md), резервные копии — [backup-restore.md](backup-restore.md).
GitHub Actions прогоняют проверки (CI), запускают плановые задания и заполнение демо-данными; выкладки из GitHub нет.

## Как устроено
| Workflow | Когда | Что делает |
|---|---|---|
| `ci.yml` | каждый PR и push в `main` | бэкенд (параллельные job `lint` / `tests` / `api-docs` + агрегатор `backend`, см. ниже): страж `scripts/mysql-only-guard.mjs`, Pint, PHPStan, PHPUnit на MySQL 8.4 (сервис в CI); фронт: lint, test, build; расширение (`extension`): lint, typecheck, test, package → артефакт `sinhrm-clipper` (zip); gitleaks; `docs` (содержательная правка `docs/modules/<модуль>.md` и тест в том же модуле — [development.md](development.md)); `worklog` |
| `demo-fill.yml` | вручную (Run workflow, флаг `reset`) | заполняет стенд синтетическими данными (один филиал «Тестовий філіал», пометка « [ТЕСТ]» в конце имён) по шагам (`confirm=demo&step=…`) или удаляет только строки из `demo_records` и старые тестовые строки (`reset`; с `dry` — только показывает, что удалит); секрет `X-Ops-Secret` не покидает GitHub Actions |
| `cron.yml` | каждые 30 мин (и вручную: Run workflow) | обычный `curl -X POST <API_URL>/api/ops/jobs/run` с `X-Ops-Secret` (секрет только через `env`, не в тексте скрипта) — все `ScheduledJob` (напоминания Scripts, начисление отпусков, шаги воркфлоу `workflows.tick` и др.); в лог — только счётчики и вердикт `jobs: ok/FAILED`. Адрес API — переменная `API_URL` в самом workflow; на IT STEP вызов может делать планировщик DevOps ([deploy-mysql.md](deploy-mysql.md#плановые-задания-cron)) |
| `night-window.yml` | раз в неделю (понедельник 05:17 UTC) и вручную | весь backend PHPUnit под `faketime` 22:30 UTC (окно, где дата UTC и Киева различается); не входит в «Protect main» — [development.md](development.md#ночное-окно-utc-против-киева) |

### Раскладка CI: параллельные job и обязательные проверки
Бэкенд в `ci.yml` разбит на три параллельных job: `lint` (страж MySQL, Pint `--parallel` + PHPStan, без БД; кеши
результатов Pint и PHPStan в `actions/cache`), `tests` (MySQL 8.4, PHPUnit + покрытие не ниже 70 %) и `api-docs`
(миграции, экспорт OpenAPI через Scramble → артефакт `openapi`, проверка размера прод-бандла `< 200 MB`).
Job `backend` — агрегатор: `needs` всех трёх, `if: always()`, зелёный только если все три `success`.

Job `tests` идёт на MySQL 8.4 — единственной СУБД проекта ([ADR 0010](../adr/0010-mysql.md)). На инфраструктуре IT STEP:
`DB_CONNECTION=mysql`, `DB_URL=mysql://<user>:<password>@<host>:3306/<db>` ([deploy-mysql.md](deploy-mysql.md)).
Job `frontend`: `ng lint`, `ng test --watch=false --coverage` (Vitest + `@vitest/coverage-v8`) с порогами покрытия
в `frontend/angular.json` → `test.options.coverageThresholds`: statements 57,5 %, branches 63,5 %, functions 55 %,
lines 64,5 % — замер 08.10.2026 (59,9 / 65,8 / 57,6 / 67,0 %, PR #207) минус запас ≈ 2,5 п.п.; ниже порога job падает. Порог
поднимаем вместе с новыми тестами, не опускаем. Локальный `ng test` без `--coverage` пороги не проверяет.
Покрытие считается только по файлам, которые импортирует хоть один спек: новый спек на ранее не загружавшуюся
страницу добавляет в знаменатель её непокрытые функции, поэтому процент функций может слегка просесть при росте
числа покрытых строк — запас порога это учитывает.

Ruleset «Protect main» требует проверки с именами **ровно** `backend`, `frontend`, `extension`, `security`, `docs`, `worklog`.
Эти job **нельзя переименовывать и удалять**: PR будет вечно ждать отсутствующую проверку. Новые части бэкенда
добавляются в `needs` агрегатора, а не в ruleset.

Секрет GitHub Actions: `OPS_SECRET` (`cron.yml`, `demo-fill.yml`). В GitHub нет доступа к БД: задания и демо-данные
выполняет API по защищённым эндпоинтам. Переменные окружения API (`DB_URL` — `config/database.php` читает её, запасное
имя `DATABASE_URL`; `APP_KEY`, `APP_ENV`, `SUPERADMIN_EMAIL`, `OPS_SECRET` — то же значение, что в секрете GitHub Actions;
`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` — OAuth-клиент входа) задаются в окружении хостинга; полный перечень —
[secrets.md](../architecture/secrets.md) и [itstep-app-handoff.md](itstep-app-handoff.md#переменные-приложения).

## Выкладка релиза
1. `node scripts/stamp-build.mjs` — записывает SHA собранной ревизии (ниже).
2. Backend: `cd backend && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader`; document root —
   `backend/public`.
3. Frontend: `cd frontend && npm ci && npm run build`; публиковать `frontend/dist/frontend/browser` как SPA.
4. `php artisan migrate --force` до переключения трафика ([deploy-mysql.md](deploy-mysql.md#миграции)).
5. Security headers и маршруты (`/api/*`, `/sanctum/*` → Laravel, остальное → `index.html`, `/build.json` без кеша) —
   эталон в `frontend/vercel.json` (его проверяют `frontend/scripts/vercel-headers.test.mjs` и
   `scripts/stamp-build.test.mjs`); на web-сервере IT STEP те же правила настраивает DevOps.

## Как проверить
`curl https://<внешний-origin>/api/health` → `{"ok":true,...}` (проверяет подключение к MySQL; при ошибке — HTTP 503).

## Идентификатор реально собранной ревизии
Перед сборкой выполняется `node scripts/stamp-build.mjs`. Он получает SHA через Git из checkout (не из переменных CI)
и пишет его в `backend/build.json` и `frontend/public/build.json`. Проверяйте Web `/build.json` и API `/api/health`
(поле `version`) отдельно. Файлы содержат только публичный SHA, без путей, времени, окружения или персональных данных.
При выкладке только одной части разные SHA API и Web допустимы; прежний API нельзя объявлять новым по SHA Web.
