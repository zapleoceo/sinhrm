# SinHRM — правила для агентов

Прочитай перед любой задачей: `docs/README.md`, `docs/guides/development.md`, `docs/architecture/secrets.md`.

## Жёсткие правила
1. Одна задача = одна ветка от `main` → PR → зелёный CI → ревью Sonnet (approve) → squash-merge. В `main` не пушить.
2. Код пишет Opus 5.5; ревью — отдельный агент Sonnet: SOLID, DRY, модульность, безопасность, тесты, документация.
3. Репозиторий ПУБЛИЧНЫЙ: никаких секретов, токенов, реальных персональных данных, справочников компании,
   внутренних URL с токенами. Секреты — только в БД (`integration_secrets`) или Vercel env (DB_URL, APP_KEY, SUPERADMIN_EMAIL).
4. Изменил модуль → обнови `docs/modules/<модуль>.md` и строку в `docs/worklog.md`. CI проверяет.
5. Бэкенд: `app/Modules/<Name>` — Controller (оркестрация) → FormRequest → Service → Repository; интерфейсы + DI;
   Feature-тест на эндпоинт, Unit на сервис. Фронт: `core/` + `features/<name>`, standalone, signals, без `any`, i18n ru/uk/en.
6. Локально среду не поднимаем. Проверка — CI и preview-деплой; в PR раздел «Доказательство» с реальными curl.
7. AI-провайдеры (AI Broker / OpenRouter) НЕ вызывать, пока владелец не утвердил модели и промпты (флаг в админке).
8. Метрики сабагентов — ledger вне репозитория (`D:\Projects\HRM\docs\tasks\*.agent-metrics.tsv`).

## Команды (CI)
Бэкенд: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test --coverage --min=70`.
Фронт: `npx ng lint`, `npx ng test --watch=false`, `npx ng build`.
