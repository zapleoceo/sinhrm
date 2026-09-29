# Правила разработки

## Простыми словами
Каждое изменение делается отдельно, проверяется автоматически и человеком-ревьюером (агентом), и только потом
попадает в рабочую версию. Документация меняется вместе с кодом — иначе изменение не примут.

## Процесс (обязателен)
1. Задача → ветка от `main`: `feat/<кратко>`, `fix/<кратко>`, `docs/<кратко>`, `chore/<кратко>`.
2. Код пишет Opus 5.5. Коммиты — Conventional Commits (`feat: …`, `fix: …`).
3. Pull Request по шаблону: что, зачем, **доказательство** (вывод CI, запрос к preview-API), документация.
4. CI (GitHub Actions) должен быть зелёным: линт, статанализ, тесты, покрытие, сборка, gitleaks, проверка документации.
5. Ревью Sonnet 5.5: SOLID, DRY, модульность, безопасность, тесты, документация. Без approve — не мержим.
6. Merge только squash в `main` → автодеплой в prod → smoke-проверка `/api/health`.

## Правила кода
**Бэкенд (Laravel):** PSR-12 + `declare(strict_types=1)` (Pint), PHPStan level 6 (Larastan). Контроллер — только
оркестрация; валидация — FormRequest; логика — Service; SQL — только Repository; зависимости — через интерфейсы и DI.
Каждый эндпоинт — Feature-тест, каждый сервис — Unit-тест. Никаких секретов в конфиге.

**Браузерное расширение (`extension/`):** TypeScript без фреймворка, esbuild, Vitest + jsdom на вымышленных HTML-фикстурах
(реальные страницы сайтов в репозиторий не копируем), ESLint; `npm run lint|typecheck|test|package` — job `extension` в CI.

**Фронтенд (Angular):** standalone, signals, `inject()`, `OnPush`, typed forms, без `any`, HTTP только через сервисы
в `core/api`, строки интерфейса — через i18n (ru/uk/en). Тесты — Vitest.

## Документация
Изменил модуль → обнови `docs/modules/<модуль>.md` (CI проверяет). Имя страницы — имя модуля в kebab-case
(`GoogleWorkspace` → `google-workspace.md`, `HiringRequests` и `features/hiring-requests` → `hiring-requests.md`, `Time` и `features/time` → `time.md`, `features/mail-agent` → `mail-agent.md`, папка `extension/` → `extension.md`; исключение-синоним: `TimeOff` и `features/timeoff` → `timeoff.md`). Структура страницы:
«Что это и зачем» (просто) → «Как пользоваться» → «Как устроено» (техника) → «Как проверить».
Архитектурные решения — `docs/adr/NNNN-название.md`.

### Журнал работ
Каждый PR добавляет **один новый файл** `docs/worklog.d/<YYYY-MM-DD>-<slug>.md`: front matter (`date`, `area`, `pr` — необязательно)
и 1–3 строки простым текстом — что изменилось и где смотреть. Формат и шаблон — [worklog.d/README.md](../worklog.d/README.md).
`docs/worklog.md` руками не правим (раньше каждая строка там давала конфликт между параллельными PR): фрагменты — источник истины,
хронологию (фрагменты + статичная история «Ранее» из `docs/worklog.md`) собирает сборка справки: «Довідка» → «Журнал работ»
(`frontend/scripts/build-docs.mjs`); локально — `node scripts/worklog-build.mjs --print`. Никакой workflow в `main` не коммитит.
CI job `worklog` падает, если в PR нет валидного фрагмента; исключения — метка `no-worklog` (затем Re-run job),
PR только в `docs/` и `.github/`, dependabot. Метку `no-worklog` может поставить любой с правом triage — ставим только
для правок без изменения поведения (опечатки в коде, откат); в остальных случаях запись обязательна.

### Что проверяет CI в job `docs`
Оба правила — нижняя планка, а не доказательство качества (покрытие меряют job `backend` / `frontend` / `extension`). Логика — `scripts/pr-checks-lib.mjs`, тесты — `scripts/pr-checks.test.mjs`.
- **Документация вместе с кодом** (`scripts/docs-check.mjs`): изменился код модуля (`backend/app/Modules/<M>`, `frontend/src/app/features/<f>`, `core`, `extension/`, кроме тестов) → его `docs/modules/<модуль>.md` изменён **содержательно**: хотя бы одна добавленная строка не короче 20 значимых символов (правка пробела, пустой строки или точки не считается). Изменились миграции, `backend/routes`, `config` или `bootstrap` → содержательно изменена любая страница в `docs/modules`, `architecture`, `guides` или `adr`. Запись в `docs/worklog.d` докой не считается.
- **Тесты вместе с кодом** (`scripts/tests-check.mjs`): изменился код модуля/фичи/расширения → добавлен или изменён тест в **том же** модуле (`backend/tests/{Feature,Unit}/<M>/`, `*.spec.ts` той же фичи или `core`, `extension/tests/`). Не требуют теста: `Providers`, `Contracts`, `Enums`, `Models`, `Database`, `DTO`, `Exceptions`, файлы `*.model.ts`, `*.routes.ts`, `types.ts`, чисто удалённый код.
- **Исключение для тестов:** метка `no-tests-needed` (правка не меняет поведение: переименование, комментарии, откат) — ставит ревьюер, после метки Re-run job; dependabot проходит сам. Исключения для документации нет.

## Проверка входа и сессий
Вход через Google работает только на prod-домене `sinhrm.vercel.app`: redirect URI в Google зарегистрирован только
для него, а Sanctum считает stateful только этот домен. Preview-деплои проверяют API и интерфейс без входа;
сценарии авторизации — Feature-тестами и smoke на проде после merge.

## Локально
Локальную среду не поднимаем: тесты и сборка выполняются в CI, проверка — на preview-деплое PR.
