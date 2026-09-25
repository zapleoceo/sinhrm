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
- Фронт: `core/api/health.service.ts` (ошибка сети → отчёт «unreachable»), экран `features/core/status.page.ts`.

## Как проверить
Тесты: `tests/Feature/Core/HealthTest.php`, `tests/Unit/Core/HealthServiceTest.php`, `health.service.spec.ts`.
Вручную: `curl -i https://sinhrm.vercel.app/api/health`.

## Подключение к Neon из Vercel
Библиотека libpq в рантайме vercel-php не поддерживает SNI, и Neon отвечает «Endpoint ID is not specified».
`Support\NeonConnectionConfig` (применяется в `CoreServiceProvider::register`) разбирает `DATABASE_URL` и передаёт
id эндпоинта внутри пароля (`endpoint=<id>;<пароль>`) — документированный обход Neon. Применяется только если libpq < 14
(`PGSQL_LIBPQ_VERSION`): современный клиент (CI) шлёт SNI, и тогда префикс ломает пароль. Для не-Neon URL ничего не меняется.
Проверено: до исправления `/api/health` → 503 с этой ошибкой, после → 200.

## Точка входа Vercel
`backend/api/index.php` подменяет `SCRIPT_NAME` на `/index.php`: иначе Laravel считает `/api` базовым путём и
`/api/health` превращается в `/health` (404). API-only: веб-маршрутов нет; на `sinhrm-api.vercel.app/` — 404. Публичный `sinhrm.vercel.app/` — это фронтенд.
Контракт проверки здоровья — `/api/health` (зависимости); `/up` — встроенная проверка Laravel «процесс жив», без БД.
