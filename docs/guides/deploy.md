# Деплой

Для app-side runtime/build requirements при переносе с Vercel см. [хенд-офф приложения для Itstep](itstep-app-handoff.md). Этот файл ниже описывает действующий Vercel workflow.

## Простыми словами
Как только изменение принято в `main`, GitHub сам прогоняет тесты и выкладывает новую версию на Vercel.
Тестовая копия (preview) выкладывается только для Pull Request с меткой `preview`, ссылка появляется в комментарии к PR.
Причина — лимит Vercel Hobby: 100 выкладок в сутки (каждый зелёный push в PR давал две — API и сайт; 2026-09-26 лимит
исчерпан, прод не обновлялся). Прод (`main`) выкладывается всегда; одновременно идёт только одна выкладка на ветку,
более свежий зелёный CI отменяет ожидающую (`concurrency`).

### Деплоится только то, что изменилось
Каждый прогон раньше выкладывал оба проекта (API и Web) независимо от того, что реально поменялось. Теперь job
`gate` смотрит на изменённые пути и решает отдельно для API и для Web, нужен ли новый деплой:
- `api=true`, если менялось что-то в `backend/**` или сам `.github/workflows/deploy.yml`;
- `web=true`, если менялось что-то в `frontend/**`, `docs/**` (документация встраивается в SPA-страницу помощи)
  или `.github/workflows/deploy.yml`;
- остальное (`extension/**`, `README*`, `rest/**` и т.п.) не включает ни то, ни другое.

Для `main` (прод) база сравнения — sha последнего успешного продовского деплоя (последний прогон `deploy.yml` на
`main`, где job `deploy` завершился `success`); если такого прогона не нашлось (например, самый первый запуск) —
деплоятся оба проекта. Для PR/preview база — merge-base ветки PR и `main`.

**Preview: Web форсирует API.** Для превью, если меняется `web`, `api` форсируется в `true` тоже, даже если
backend не менялся — иначе превью-сайт указывал бы на прод-API (`/api` rewrite), а это реальные данные клиентов
за публичным превью-логином. Для прода (`main`) `api` и `web` полностью независимы — правило форсирования там не
применяется.

**Сбой = деплоим оба.** Любая ошибка при вычислении (ошибка/лимит GitHub API, 404 на сравнении после
force-push), недоверенный diff (300+ файлов — лимит compare API, статус `diverged`/`behind` для прода, нет
merge-base) или падение самого шага — всё это даёт `api=true, web=true` с предупреждением в логе. Лишний деплой
дешевле пропущенного продового.

Если оба флага `false` — job `deploy` не делает ничего (оба шага сборки/деплоя пропускаются), без ошибки.

Логика классификации путей и поиска sha последнего успешного деплоя вынесена в
[`.github/scripts/deploy-gate.js`](../../.github/scripts/deploy-gate.js) и покрыта юнит-тестами
(`.github/scripts/deploy-gate.test.js`, команда `node .github/scripts/deploy-gate.test.js`).

## Как устроено
| Workflow | Когда | Что делает |
|---|---|---|
| `ci.yml` | каждый PR и push в `main` | бэкенд (параллельные job `lint` / `tests` / `api-docs` + агрегатор `backend`, см. ниже): Pint, PHPStan, PHPUnit на Postgres (сервис в CI); фронт: lint, test, build; расширение (`extension`): lint, typecheck, test, package → артефакт `sinhrm-clipper` (zip); gitleaks; `docs` (содержательная правка `docs/modules/<модуль>.md` и тест в том же модуле — [development.md](development.md)); `worklog` |
| `deploy.yml` | после зелёного CI (push в `main` / PR) | `vercel pull` → `vercel build` → `vercel deploy --prebuilt` для `sinhrm-api` и `sinhrm`; миграции через `POST /api/ops/migrate` (prod) / `?fresh=1` (preview, синтетика) |
| `demo-fill.yml` | вручную (Run workflow, флаг `reset`) | заполняет прод синтетическими данными (один филиал «Тестовий філіал», пометка « [ТЕСТ]» в конце имён) по шагам (`confirm=demo&step=…`) или удаляет только строки из `demo_records` и старые тестовые строки (`reset`; с `dry` — только показывает, что удалит); секрет `X-Ops-Secret` не покидает GitHub Actions |
| `cron.yml` | каждые 30 мин (и вручную: Run workflow) | обычный `curl -X POST https://sinhrm-api.vercel.app/api/ops/jobs/run` с `X-Ops-Secret` (секрет только через `env`, не в тексте скрипта) — все `ScheduledJob` (напоминания Scripts, начисление отпусков, шаги воркфлоу `workflows.tick` и др.); в лог — только счётчики и вердикт `jobs: ok/FAILED` |

### Раскладка CI: параллельные job и обязательные проверки
Бэкенд в `ci.yml` разбит на три параллельных job: `lint` (Pint `--parallel` + PHPStan, без Postgres; кеши
результатов Pint и PHPStan в `actions/cache`), `tests` (Postgres, PHPUnit + покрытие не ниже 70 %) и `api-docs`
(миграции, экспорт OpenAPI через Scramble → артефакт `openapi`, проверка размера прод-бандла `< 200 MB`).
Job `backend` — агрегатор: `needs` всех трёх, `if: always()`, зелёный только если все три `success`.

Job `tests-mysql` (MySQL 8.4, тот же полный PHPUnit с покрытием) — **необязательный и не входит в агрегатор `backend`** на переходный период двойной поддержки ([ADR 0010](../adr/0010-mysql-dual-support.md)); в «Protect main» не добавлять, пока не станет стабильно зелёным. Целевая БД на инфраструктуре IT STEP — MySQL 8.4: `DB_CONNECTION=mysql`, `DB_URL=mysql://<user>:<password>@<host>:3306/<db>` ([itstep-app-handoff.md](itstep-app-handoff.md)); Vercel-прод до переезда остаётся на Neon (`pgsql`).
Job `frontend`: `ng lint`, `ng test --watch=false --coverage` (Vitest + `@vitest/coverage-v8`) с порогами покрытия
в `frontend/angular.json` → `test.options.coverageThresholds`: statements 45,5 %, branches 57 %, functions 54,5 %,
lines 54 % — замер 02.10.2026 (47,6 / 59,1 / 56,7 / 56,3 %) минус запас ≈ 2 п.п.; ниже порога job падает. Порог
поднимаем вместе с новыми тестами, не опускаем. Локальный `ng test` без `--coverage` пороги не проверяет.

Ruleset «Protect main» требует проверки с именами **ровно** `backend`, `frontend`, `extension`, `security`, `docs`, `worklog`.
Эти job **нельзя переименовывать и удалять**: PR будет вечно ждать отсутствующую проверку. Новые части бэкенда
добавляются в `needs` агрегатора, а не в ruleset. Workflow называется `CI` — на это имя подписан `deploy.yml`
(`workflow_run`), его статус учитывает все job. В `deploy.yml` версия Vercel CLI закреплена (`vercel@~61.0.0`),
обновлять осознанно.

Секреты GitHub Actions: `VERCEL_TOKEN`, `VERCEL_ORG_ID`, `VERCEL_PROJECT_ID_API`, `VERCEL_PROJECT_ID_WEB`, `OPS_SECRET`.
В GitHub нет доступа к БД: миграции выполняет API по защищённому эндпоинту.
Переменные окружения приложений (`DATABASE_URL`, `APP_KEY`, `APP_ENV`, `SUPERADMIN_EMAIL`, `OPS_SECRET`) — в настройках проектов Vercel.

Защита preview-деплоев Vercel (Vercel Authentication) отключена: на preview только синтетические данные,
а эндпоинты закрыты авторизацией или `X-Ops-Secret`. Поэтому CI обращается к ним обычным `curl`
(`vercel curl` ломает передачу аргументов после `--`: «URL rejected: Malformed input»).

### Preview ходит в preview-API
В `frontend/vercel.json` адрес API — прод (`sinhrm-api.vercel.app`). Для `TARGET=preview` шаг «Web — build & deploy»
перед `vercel build` переписывает в рабочей копии CI два правила (`/api/:path*` и `/sanctum/:path*`) на URL
preview-API этого же PR (`steps.api.outputs.url`) — node-однострочник; URL должен быть `https://*.vercel.app`, и
правил должно быть ровно два, иначе шаг падает. Закоммиченный файл и prod-деплой не меняются. Проверка: в логе шага
выводится число вхождений preview-URL в `vercel.json` (`2`); на preview `curl <web-preview>/api/health` отвечает
preview-API. Вход Google на preview по-прежнему не работает (см. [development.md](development.md)).

Деплой запускается только для веток этого репозитория: PR из форков не получают секреты и не деплоятся.

## Как проверить
`curl https://sinhrm.vercel.app/api/health` → `{"ok":true,...}`.

- Выкладка только сайта (сервер не менялся): прод-сайт берёт адрес API из закоммиченного `frontend/vercel.json`, поэтому проверка адреса API нужна только для preview (исправлено 2026-09-28: 24 ночные попытки падали с «bad API url»).

## Идентификатор реально собранной ревизии
Перед `vercel build` Deploy выполняет `node scripts/stamp-build.mjs`. Он получает SHA через Git из checkout,
а не `github.sha` контекста workflow_run; generated JSON попадает в API function и Web static assets.
Проверяйте Web `/build.json` и API `/api/health` (поле `version`) отдельно по соответствующему Deploy run.
Файлы содержат только публичный SHA, без путей, времени, окружения или персональных данных.
При частичном production deploy разные SHA проектов допустимы; прежний API нельзя объявлять новым по SHA Web.
