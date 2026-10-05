# PROD-39: app handoff для инфраструктуры Itstep

## Стан

- Шаг: проверить готовность приложения и подготовить handoff-документацию.
- Выполнено: проверены backend/frontend manifests, публичная Laravel точка входа, конфигурация SPA proxy, DB/session/cache/queue, health endpoint, GitHub deploy и cron workflows. Добавлен guide с требованиями runtime/build, env names, routing, миграциями, health и scheduled jobs.
- Свидетельства: `backend/composer.json` и `composer.lock`; `backend/public/index.php`; `backend/api/index.php`; `backend/vercel.json`; `frontend/package.json`, `package-lock.json`, `angular.json`, `vercel.json`; `.github/workflows/deploy.yml`, `cron.yml`, `ci.yml`; соответствующие `backend/config/*` и модули Core/Documents.
- Решение: приложение можно передать как PHP/Laravel backend + статический Angular SPA при сохранении одного browser origin для cookie-сессии. Новые серверные адаптеры или worker не нужны в проверенном baseline; `ScheduledJob` запускаются защищённым HTTP cron endpoint.
- Проверки: `node scripts/worklog.test.mjs` — 29/29 PASS; `git diff --check` — PASS. `frontend/npm run test:docs` не запустил docs suite: checkout не содержит `frontend/node_modules` (ошибка `ERR_MODULE_NOT_FOUND: marked`); UI header tests внутри команды прошли 2/2. Полный frontend/backend CI остаётся обязательным на итоговом SHA.
- Следующий шаг: открыть draft PR, дождаться полного CI и отдельного Astra review на итоговом SHA.
- Блокеры: internal KB search для PROD-39 вернул HTTP 502; поиск Confluence по Vercel/deployment/runtime вернул 0 результатов. Это не блокирует работу; основанием служат текущие файлы репозитория.
- Обновлено: 2026-10-05.

## Контекст з KB

- Запросы: `PROD-39 SinHRM Itstep application deployment portability handoff`; `SinHRM Vercel deployment backend runtime frontend migration scheduler hosting`.
- Источники: Rovo/Jira-Confluence search; первый запрос завершился 502, второй не нашёл Confluence-страниц. Отдельная внешняя KB информация не использовалась.
