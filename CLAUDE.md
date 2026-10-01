# SinHRM — правила для агентов

Прочитай перед любой задачей: `docs/README.md`, `docs/guides/development.md`, `docs/architecture/secrets.md`.

## Жёсткие правила
1. Одна задача = одна ветка от `main` → PR → зелёный CI → ревью Sonnet 5.5 (approve) → squash-merge. В `main` не пушить.
2. Код пишет Opus 5.5; ревью — отдельный агент Sonnet 5.5 (с 29.09.2026, раньше — Sonnet 5): SOLID, DRY, модульность, безопасность, тесты, документация.
3. Репозиторий ПУБЛИЧНЫЙ: никаких секретов, токенов, реальных персональных данных, справочников компании,
   внутренних URL с токенами. Секреты — только в БД (`integration_secrets`) или Vercel env (DB_URL, APP_KEY, SUPERADMIN_EMAIL).
4. Изменил модуль → обнови `docs/modules/<модуль>.md`. Каждый PR добавляет запись в журнал — новый файл
   `docs/worklog.d/<YYYY-MM-DD>-<slug>.md` (формат — `docs/worklog.d/README.md`); `docs/worklog.md` в PR не править —
   хронологию собирает сборка справки («Довідка» → «Журнал работ»). Без записи — только метка `no-worklog`. CI проверяет (jobs `docs`, `worklog`).
   Правка кода модуля требует **содержательной** правки его `docs/modules/<модуль>.md` (пробел не считается) и теста в том же модуле
   (исключение для тестов — метка `no-tests-needed`; правила — `docs/guides/development.md`, job `docs`).
5. Бэкенд: `app/Modules/<Name>` — Controller (оркестрация) → FormRequest → Service → Repository; интерфейсы + DI;
   Feature-тест на эндпоинт, Unit на сервис. Фронт: `core/` + `features/<name>`, standalone, signals, без `any`, i18n ru/uk/en.
6. Локально среду не поднимаем. Проверка — CI и preview-деплой; в PR раздел «Доказательство» с реальными curl.
7. AI утверждён владельцем (AI Broker; возможность по функции, сейчас `chat:fast`). Вызывать провайдеров только через `Ai/Services/AiService` (флаг в админке,
   лимиты, без логирования промптов); новый/изменённый промпт — новая версия и текст в `docs/modules/ai.md`.
8. Метрики сабагентов — ledger вне репозитория (`D:\Projects\HRM\docs\tasks\*.agent-metrics.tsv`).
9. Все файлы — рабочие копии, скрипты, скриншоты, выгрузки, любые временные файлы — только в `D:\Projects\`. Не в `C:\`, не в `%TEMP%`, не в `/tmp`, не в папках профиля. Нужна папка для временных файлов — создаём её там же, под проект: `D:\Projects\_tmp\<проект>\` (для SinHRM — `D:\Projects\_tmp\sinhrm\`; рабочие копии агентов — `D:\Projects\sinhrm-wt\<имя>`). Как только временные файлы больше не нужны (PR влит, задача закрыта, проверка закончена) — удаляем их и пустую папку. Секреты во временные файлы не кладём. Подробнее — `docs/guides/development.md`, раздел «Файлы и временные папки».

## Команды (CI)
Бэкенд: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test --coverage --min=70`.
Фронт: `npx ng lint`, `npx ng test --watch=false`, `npm run test:docs`, `npm run build` (собирает справку `/docs` из `docs/` и приложение).
