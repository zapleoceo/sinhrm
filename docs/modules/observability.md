# Модуль Observability (журнал ошибок)

## Что это и зачем
Ошибки сервера и браузера сохраняются в базе приложения и видны суперадмину — без внешних сервисов вроде Sentry.
Одинаковые ошибки собираются в одну группу со счётчиком, личных данных в журнале нет, хранится 30 дней.

## Как пользоваться
Суперадмин: «Адміністрування → Помилки» (`/admin/errors`). Список групп (новые сверху): сколько раз, откуда
(server / web), тип и текст ошибки, когда последний раз. Нажмите на группу — место в коде, маршрут, id пользователя,
первый и последний раз. Переключатель «Вирішено» убирает группу из «Відкриті»; если ошибка повторится, группа
откроется снова.

## Как устроено
Полное описание — [architecture/observability.md](../architecture/observability.md): таблица `error_events`,
`Services\ErrorRecorder` (репортер исключений в `bootstrap/app.php`, никогда не бросает), `Services\ErrorLogPruneJob`
(`errors.prune`, 30 дней), `Http\Controllers\ErrorLogController` (`/api/errors/*`), фронт —
`features/observability/errors.page.ts`, `core/errors/*`.

## Как проверить
`tests/Feature/Observability/ErrorLogTest.php`, `frontend/src/app/core/errors/error-reporter.spec.ts`.
