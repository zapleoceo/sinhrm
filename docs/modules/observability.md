# Модуль Observability (журнал ошибок)

## Что это и зачем
Ошибки сервера и браузера сохраняются в базе приложения и видны суперадмину — без внешних сервисов вроде Sentry.
Одинаковые ошибки собираются в одну группу со счётчиком, личных данных в журнале нет, хранится 30 дней.

## Как пользоваться
Суперадмин: «Адміністрування → Помилки» (`/admin/errors`). Список групп (новые сверху): сколько раз, откуда
(server / web), тип и текст ошибки, когда последний раз. Нажмите на группу — место в коде, маршрут, id пользователя,
первый и последний раз. Переключатель «Вирішено» убирает группу из «Відкриті»; если ошибка повторится, группа
откроется снова. На телефоне длинные имена классов исключений (`App\Modules\…\Exception`) переносятся внутри карточки —
страница не прокручивается вбок.

## Как устроено
Полное описание — [architecture/observability.md](../architecture/observability.md): таблица `error_events`,
`Services\ErrorRecorder` (репортер исключений в `bootstrap/app.php`, никогда не бросает), `Services\ErrorLogPruneJob`
(`errors.prune`, 30 дней), `Http\Controllers\ErrorLogController` (`/api/errors/*`), фронт —
`features/observability/errors.page.ts`, `core/errors/*`.

Модуль базовый (`$coreModule = true`, [modules-access.md](modules-access.md)): на странице «Модулі» его нельзя
выключить. Причина — `POST /api/errors/client` принимает отчёты от каждого вошедшего пользователя при любой роли;
сам журнал и так закрыт для всех, кроме суперадмина, а `errors.prune` работает всегда.

**Вид (рестайл C «Маршрут», 2026-10-02).** Группа ошибки — карточка с красной «рельсой» 4px; решённая — пунктирная рамка и нейтральная рельса (без opacity: текст не теряет контраст); счётчик «×N» — моно-пилюля, время — моно, пустой список — `.app-empty`. Тест вида — `features/observability/observability.restyle.spec.ts` (контракт стилей: только токены темы, без hex, линии 1.5px, без «бледности» через opacity).

### Общие примитивы фронта
Общий код фронта лежит в `frontend/src/app/core` ([core.md](core.md)); фича его только вызывает.
- HTTP-сервис фичи снимает обёртку ответа `{ data }` общим оператором `unwrapData()` (`core/api/unwrap-data.ts`, тип `DataEnvelope<T>` из `core/api/api.model.ts`) вместо своего `map((r) => r.data)`; параметры запроса без пустых значений — `toParams` из `core/api/http-params.ts`, страница списка — `Paged<T>` оттуда же. Контракт API не менялся.

## Как проверить
`tests/Feature/Observability/ErrorLogTest.php`, `tests/Feature/Core/ModuleAccessTest.php`, `frontend/src/app/core/errors/error-reporter.spec.ts`.
