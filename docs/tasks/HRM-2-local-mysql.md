# HRM-2: локальный запуск SinHRM на MySQL 8.4

## State

- Время: 2026-10-09 06:24 UTC.
- Область: только ноутбук; общий MySQL 3307, Vercel, Neon, stage и Jira не менялись.
- Рабочая копия: `D:/Projects/HRM/worktrees/local-mysql-runtime`, ветка `feat/local-mysql-runtime`. Источники: PR #174 `be14ee16` и PR #215 `75728547`; объединение и локальные исправления сохранены в этой рабочей ветке; итоговый SHA смотреть через `git rev-parse HEAD`.
- MySQL: отдельный Community Server 8.4.8 на `127.0.0.1:3308`; UTC, `utf8mb4_0900_ai_ci`, strict SQL mode, packet 64 MiB, 0 таблиц вне InnoDB. Пароли и APP_KEY созданы только локально в закрытых файлах.
- Базы: `sinhrm_local`, `sinhrm_restore_check`, `sinhrm_migration_probe`. На пробной базе полный `migrate` → `migrate:reset` → `migrate` прошёл; регрессионный тест отката `generalize_tasks_table` прошёл (10 assertions).
- Восстановление: SHA-256 исходного тестового дампа совпал с манифестом; две чистые БД после импорта совпали по точным числам строк 116 таблиц; контрольная `sinhrm_restore_check` совпала с манифестом по каждой таблице; users 135, candidates 179, employees 130, applications 150 до HTTP smoke. База `sinhrm_restore_check` сохранена как неизменённое контрольное восстановление.
- Процессы: API `127.0.0.1:8000`, Angular `127.0.0.1:4200`, queue worker. Текущие PID проверяются через `netstat` и `Get-Process`, поскольку dev server перезапускает PHP worker при изменении кода. `/api/health` через Angular proxy — HTTP 200; очередь обработала безвредную задачу.
- Вход: локальный тестовый вход по CSRF/cookie прошёл, `/api/auth/me` — 200; неверный секрет — 404, гость — 401. Unit guard отказывает stage/prod, PHP-FPM, внешнему IP/Host и выключенному флагу.
- Сценарии: через web origin прошли GET candidates/people/vacancies/tasks/dashboard/nav badges, создание/чтение/обновление синтетического кандидата. В Chrome после локального входа загрузился дашборд SinHRM с данными БД. Google OAuth и внешние интеграции локально не вызывались.
- Проверки: PHPStan, Pint, MySQL guard, docs-links, frontend lint/build; 180 frontend test files, 1164 pass и 1 skip. Полный backend PHPUnit на `sinhrm_migration_probe`: 1817 pass, 2 skip, 16 783 assertions. Первый прогон дал 7 ошибок из-за отсутствия `localhost` в только локальном `SANCTUM_STATEFUL_DOMAINS`; после добавления localhost оба проблемных класса (17/17) и полный повторный прогон прошли. Coverage на локальном PHP не доступен (нет PCOV/Xdebug).
- Следующий шаг: независимое review объединённых изменений и CI перед публикацией. Локальный результат работает; production/stage переключений не было.