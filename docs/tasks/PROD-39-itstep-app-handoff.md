# PROD-39: app handoff для инфраструктуры Itstep

## Стан

- Шаг: закрыть Astra замечания к draft PR и повторить проверки на обновлённом точном SHA.
- Выполнено: проверены backend/frontend manifests, публичная Laravel точка входа, конфигурация SPA proxy, DB/session/cache/queue, health endpoint, GitHub deploy и cron workflows. Добавлен guide с требованиями runtime/build, env names, routing, миграциями, health и scheduled jobs.
- Свидетельства: `backend/composer.json` и `composer.lock`; `backend/public/index.php`; `backend/api/index.php`; `backend/vercel.json`; `frontend/package.json`, `package-lock.json`, `angular.json`, `vercel.json`; `.github/workflows/deploy.yml`, `cron.yml`, `ci.yml`; соответствующие `backend/config/*` и модули Core/Documents. PR #155 (`e77b200c6abc7c02405a4f233553bcc0dee961c2`) создан draft и прикреплён. Astra просмотрел этот SHA и запросил две исправимые документальные детали: обязательный Google login/callback и сохранение `zend.exception_ignore_args=1` для direct PHP-FPM entrypoint; обе внесены в локальный diff.
- Решение: приложение можно передать как PHP/Laravel backend + статический Angular SPA при сохранении одного browser origin для cookie-сессии. Новые серверные адаптеры или worker не нужны в проверенном baseline; `ScheduledJob` запускаются защищённым HTTP cron endpoint.
- Проверки исходного SHA: `node scripts/worklog.test.mjs` — 29/29 PASS; `git diff --check` — PASS. `frontend/npm run test:docs` не запустил docs suite: checkout не содержит `frontend/node_modules` (ошибка `ERR_MODULE_NOT_FOUND: marked`); UI header tests внутри команды прошли 2/2. На `e77b200...` CI #37283169169: backend lint/API docs, frontend, extension, security, docs, worklog прошли; backend tests и ui-parity ещё выполнялись до исправленного diff, поэтому этот прогон не считать финальной проверкой.
- Следующий шаг: коммит/пуш исправлений, дождаться полного CI и повторного отдельного Astra review на итоговом SHA.
- Блокеры: internal KB search для PROD-39 вернул HTTP 502; поиск Confluence по Vercel/deployment/runtime вернул 0 результатов. Это не блокирует работу; основанием служат текущие файлы репозитория.
- Обновлено: 2026-10-05.

## Контекст з KB

- Запросы: `PROD-39 SinHRM Itstep application deployment portability handoff`; `SinHRM Vercel deployment backend runtime frontend migration scheduler hosting`.
- Источники: Rovo/Jira-Confluence search; первый запрос завершился 502, второй не нашёл Confluence-страниц. Отдельная внешняя KB информация не использовалась.
