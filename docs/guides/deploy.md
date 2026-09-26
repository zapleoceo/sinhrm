# Деплой

## Простыми словами
Как только изменение принято в `main`, GitHub сам прогоняет тесты и выкладывает новую версию на Vercel.
Тестовая копия (preview) выкладывается только для Pull Request с меткой `preview`, ссылка появляется в комментарии к PR.
Причина — лимит Vercel Hobby: 100 выкладок в сутки (каждый зелёный push в PR давал две — API и сайт; 2026-09-26 лимит
исчерпан, прод не обновлялся). Прод (`main`) выкладывается всегда; одновременно идёт только одна выкладка на ветку,
более свежий зелёный CI отменяет ожидающую (`concurrency`).

## Как устроено
| Workflow | Когда | Что делает |
|---|---|---|
| `ci.yml` | каждый PR и push в `main` | бэкенд: Pint, PHPStan, PHPUnit на Postgres (сервис в CI); фронт: lint, test, build; расширение (`extension`): lint, typecheck, test, package → артефакт `sinhrm-clipper` (zip); gitleaks; docs-check |
| `deploy.yml` | после зелёного CI (push в `main` / PR) | `vercel pull` → `vercel build` → `vercel deploy --prebuilt` для `sinhrm-api` и `sinhrm`; миграции через `POST /api/ops/migrate` (prod) / `?fresh=1` (preview, синтетика) |
| `cron.yml` | каждые 30 мин (и вручную: Run workflow) | обычный `curl -X POST https://sinhrm-api.vercel.app/api/ops/jobs/run` с `X-Ops-Secret` (секрет только через `env`, не в тексте скрипта) — все `ScheduledJob` (напоминания Scripts, начисление отпусков, шаги воркфлоу `workflows.tick` и др.); в лог — только счётчики и вердикт `jobs: ok/FAILED` |

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
