# Модуль Core

## Что это и зачем
Общий фундамент: проверка, что система жива и видит базу данных, и базовый механизм подключения модулей.

## Как пользоваться
Откройте `https://sinhrm.vercel.app` — стартовая страница показывает состояние API и его зависимостей.

## Как устроено
- `GET /api/health` → `{"version": "...", "ok": true, "checks": {"database": {"ok": true}}}`; код 200 или 503.
- Каждая зависимость — класс, реализующий `Contracts\HealthCheck`; модули добавляют свои проверки через
  `$app->tag([...], HealthCheck::class)`. Ошибка проверки не раскрывает детали подключения — только класс исключения.
- `Support\ModuleServiceProvider` — базовый провайдер модуля: подключает `routes.php` под `/api/<prefix>` и миграции из
  `Database/Migrations`.
- Фронт: `core/api/health.service.ts` (ошибка сети → отчёт «unreachable»), экран `features/status`.

## Как проверить
Тесты: `tests/Feature/Core/HealthTest.php`, `tests/Unit/Core/HealthServiceTest.php`, `health.service.spec.ts`.
Вручную: `curl -i https://sinhrm.vercel.app/api/health`.
