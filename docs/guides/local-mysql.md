# Локальный SinHRM на MySQL 8.4

Это рабочее окружение только на ноутбуке. Общий MySQL на порту 3307, Vercel и stage не используются.

- Репозиторий: `D:/Projects/HRM/worktrees/local-mysql-runtime`.
- MySQL Community Server 8.4.8: `D:/Projects/mysql84/bin/mysqld.exe`, конфигурация отдельного экземпляра `D:/Projects/HRM/local/mysql84/my.ini`, bind `127.0.0.1:3308`.
- Базы: `sinhrm_local` (приложение), `sinhrm_restore_check` (чистое восстановление), `sinhrm_migration_probe` (тесты миграций). Учётная запись имеет права только на эти базы.
- Секреты лежат в закрытом локальном каталоге `D:/Projects/HRM/local/private` и `backend/.env`; не добавлять их в Git или логи.
- API: из `backend` запустить `D:/Projects/php8/php.exe artisan serve --host=127.0.0.1 --port=8000`.
- Очередь: из `backend` запустить `D:/Projects/php8/php.exe artisan queue:work --sleep=2 --tries=1 --timeout=60`.
- Web: из `frontend` запустить `npm run start -- --host 127.0.0.1 --port 4200 --proxy-config proxy.local.json`.
- Вход в тестовый аккаунт: `http://127.0.0.1:4200/local-test-login.html`. Страница создана только локально и не попадает в Git.
- Проверка: `http://127.0.0.1:4200/api/health`, затем `GET /api/auth/me` после входа.

Периодический `/api/ops/jobs/run` и внешние интеграции здесь не запускались. Для выгрузки используется уже проверенный тестовый дамп; файл исходного дампа и база `sinhrm_restore_check` остаются нетронутыми после проверки сценариев.