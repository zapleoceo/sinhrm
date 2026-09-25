# Деплой

## Простыми словами
Как только изменение принято в `main`, GitHub сам прогоняет тесты и выкладывает новую версию на Vercel.
Для каждого Pull Request выкладывается отдельная тестовая копия (preview), ссылка появляется в комментарии к PR.

## Как устроено
| Workflow | Когда | Что делает |
|---|---|---|
| `ci.yml` | каждый PR и push в `main` | бэкенд: Pint, PHPStan, PHPUnit на Postgres (сервис в CI); фронт: lint, test, build; gitleaks; docs-check |
| `deploy.yml` | после зелёного CI (push в `main` / PR) | `vercel pull` → `vercel build` → `vercel deploy --prebuilt` для `sinhrm-api` и `sinhrm`; prod — `migrate`, preview — `migrate:fresh --seed` (только синтетика) |
| `cron.yml` | каждые 30 мин | `POST /api/jobs/run` с секретом — обработка фоновых задач |

Секреты GitHub Actions: `VERCEL_TOKEN`, `VERCEL_ORG_ID`, `VERCEL_PROJECT_ID_API`, `VERCEL_PROJECT_ID_WEB`, `CRON_SECRET`.
Переменные окружения приложений (`DB_URL`, `APP_KEY`, `SUPERADMIN_EMAIL`) — в настройках проектов Vercel.

Деплой запускается только для веток этого репозитория: PR из форков не получают секреты и не деплоятся.

## Как проверить
`curl https://sinhrm.vercel.app/api/health` → `{"ok":true,...}`.
