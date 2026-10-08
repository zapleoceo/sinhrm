# Правила разработки

## Простыми словами
Каждое изменение делается отдельно, проверяется автоматически и человеком-ревьюером (агентом), и только потом
попадает в рабочую версию. Документация меняется вместе с кодом — иначе изменение не примут.

## Процесс (обязателен)
1. Задача → ветка от `main`: `feat/<кратко>`, `fix/<кратко>`, `docs/<кратко>`, `chore/<кратко>`.
2. Код пишет Luna — владелец выбрал недорогую модель 05.10.2026; сохранённые изменения SOL продолжаются в их ветках. Коммиты — Conventional Commits (`feat: …`, `fix: …`).
3. Pull Request по шаблону: что, зачем, **доказательство** (вывод CI, запрос к preview-API), документация.
4. CI (GitHub Actions) должен быть зелёным: линт, статанализ, тесты, покрытие, сборка, gitleaks, проверка документации.
5. Выводы перепроверяет отдельный агент. Независимый агент Astra проверяет локальный и GitHub diff: SOLID, DRY, модульность, безопасность, тесты, документация. Без approve актуального commit — не мержим. Это текущая инструкция владельца; формальный GitHub approval не следует приписывать агенту без опубликованного review.
6. Разрешённый merge только squash в `main` → автодеплой в prod → smoke-проверка `/api/health` и изменённых сценариев. Общая работа над production backlog не выбирает нерешённые product/security gates. [Раунды и критерии production](../product/production-backlog.md).

## Правила кода
Для работы агентов применяй [экономию контекста и проверок](agent-efficiency.md): узкие задания, состояние на диске, краткие логи и конкретный SHA ревью. Это дополнение к обязательному процессу выше; последнее указание владельца о моделях имеет приоритет.

**Бэкенд (Laravel):** PSR-12 + `declare(strict_types=1)` (Pint), PHPStan level 6 (Larastan). Контроллер — только
оркестрация; валидация — FormRequest; логика — Service; SQL — только Repository; зависимости — через интерфейсы и DI.
Каждый эндпоинт — Feature-тест, каждый сервис — Unit-тест. Никаких секретов в конфиге.

**Браузерное расширение (`extension/`):** TypeScript без фреймворка, esbuild, Vitest + jsdom на вымышленных HTML-фикстурах
(реальные страницы сайтов в репозиторий не копируем), ESLint; `npm run lint|typecheck|test|package` — job `extension` в CI.

**Версия TypeScript в `extension/` — 6.x (решение 2026-10-08).** Мажор TypeScript 7 не ставится: `typescript-eslint` (включая последнюю 8.71.1) объявляет peer `typescript >=4.8.4 <6.1.0`, поэтому `npm ci` падает с ERESOLVE (dependabot-PR #191, job `extension`). Обновление на TS 7 возможно в том PR, где `typescript-eslint` выпустит peer с поддержкой 7.x; проверка — `npm view typescript-eslint peerDependencies`. Dependabot для `/extension` игнорирует мажорные версии `typescript` (как и для `/frontend`).

**Фронтенд (Angular):** standalone, signals, `inject()`, `OnPush`, typed forms, без `any`, строки интерфейса — через i18n (ru/uk/en).
Тесты — Vitest. **HTTP только через сервисы:** компонент не держит `HttpClient`; HTTP-сервис живёт в самой фиче
(`features/<name>/<name>.service.ts` или рядом с подфичей, например `recruiting/card/offers.service.ts`), а в `core/api` —
только общее для всех фич: `toParams`, `unwrapData`/`DataEnvelope`, `Paged`, `apiErrorKey` и сервисы самого ядра
(`health.service.ts`). Уведомления — `core/ui/notify.service.ts` ([core.md](../modules/core.md)). Общие хелперы тестов — `frontend/src/testing/`
(в сборку приложения не входят): `css(Component)` — скомпилированный CSS компонента для тестов вида `*.restyle.spec.ts`.

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
- **Документация вместе с кодом** (`scripts/docs-check.mjs`): изменился код модуля (`backend/app/Modules/<M>`, `frontend/src/app/features/<f>`, `core`, `extension/`, кроме тестов) → его `docs/modules/<модуль>.md` изменён **содержательно**: хотя бы одна добавленная строка не короче 12 букв и цифр (правка пробела, пустой строки, точки или разделителя таблицы не считается; удаление модуля доки не требует). Изменились миграции, `backend/routes`, `config` или `bootstrap` → содержательно изменена любая страница в `docs/modules`, `architecture`, `guides` или `adr`. Запись в `docs/worklog.d` докой не считается.
- **Тесты вместе с кодом** (`scripts/tests-check.mjs`): изменился код модуля/фичи/расширения → добавлен или изменён тест в **том же** модуле (`backend/tests/{Feature,Unit}/<M>/`, `*.spec.ts` той же фичи или `core`, `extension/tests/`). Правка только `.html`/`.scss` теста не требует (доку — требует). Файлы вне модулей (`backend/app/Http`, `Models`, `Providers`) проверками не покрыты. Не требуют теста: `Providers`, `Contracts`, `Enums`, `Models`, `Database`, `DTO`, `Exceptions`, файлы `*.model.ts`, `*.routes.ts`, `types.ts`, чисто удалённый код.
- **Мёртвые внутренние ссылки** (`scripts/docs-links-check.mjs`, тесты — `scripts/docs-links-check.test.mjs`): каждая относительная ссылка `[текст](путь#якорь)` в `README.md`, `CLAUDE.md`, `AGENTS.md` и `docs/**/*.md` ведёт на существующий файл или папку репозитория, а якорь — на заголовок этого файла (правила якорей GitHub, кириллица сохраняется). Внешние ссылки (`http(s)`, `mailto`) и код не проверяются. Фрагменты `docs/worklog.d/*.md` ссылаются относительно `docs/`. Локально: `node scripts/docs-links-check.mjs`.
- **Исключение для тестов:** метка `no-tests-needed` (правка не меняет поведение: переименование, комментарии, откат) — ставит ревьюер, после метки Re-run job; dependabot проходит сам. Исключения для документации нет.

## Таблицы
Сортировка и фильтры в заголовках колонок — общий компонент `core/ui/table`; как подключить и какие таблицы
ещё ждут — [tables.md](tables.md).

## Проверка входа и сессий
Вход через Google работает только на prod-домене `sinhrm.vercel.app`: redirect URI в Google зарегистрирован только
для него, а Sanctum считает stateful только этот домен. Preview-деплои проверяют API и интерфейс без входа;
сценарии авторизации — Feature-тестами и smoke на проде после merge.

## Файлы и временные папки

Правило владельца (01.10.2026): **все файлы создаём только в `D:\Projects\`**. Это относится и к временным: скриншоты, скрипты, выгрузки, результаты проверок, собранные сборки для проверки, любые промежуточные результаты.

- Нельзя: `C:\`, `%TEMP%`, `/tmp`, каталог профиля пользователя, рабочий стол, папки инструментов вне `D:\Projects\`.
- Нужна папка для временных файлов — создаём её под проект: `D:\Projects\_tmp\<проект>\` (для SinHRM — `D:\Projects\_tmp\sinhrm\`). Внутри — подпапка на задачу, например `D:\Projects\_tmp\sinhrm\board-drag\`.
- Рабочие копии агентов (git worktree) — `D:\Projects\HRM\worktrees\<имя>`; после вливания PR удаляются (`git worktree remove --force`, затем `git worktree prune`).
- **Чистим за собой:** как только временные файлы не нужны (PR влит, проверка закончена, задача закрыта) — удаляем файлы и пустую папку. В конце задачи агент проверяет, что в `D:\Projects\HRM	mp\` не осталось его файлов.
- Секреты и токены во временные файлы не кладём; ключ, нужный на время эксперимента, передаём переменной окружения, а не файлом.
- Артефакты, которые должны жить в репозитории (скриншоты для PR, примеры), кладём в `docs/assets/<тема>/` и коммитим; всё остальное — только временное.
- Ledger метрик агентов (`D:\Projects\HRM\docs\tasks\*.agent-metrics.tsv`) — телеметрия процесса, не коммитится, живёт в `D:\Projects\`.

## Локально
Локальную среду не поднимаем: тесты и сборка выполняются в CI, проверка — на preview-деплое PR.

## База данных: только MySQL 8.4

Единственная СУБД — MySQL 8.4 ([ADR 0011](../adr/0011-mysql-only.md)); другие СУБД не поддерживаются (замороженный
боевой релиз до переезда и правки в нём — только по решению владельца, [mysql-cutover.md](mysql-cutover.md)). Правила для нового кода: синтаксис других
СУБД (регистронезависимый `LIKE`-оператор, `NULLS FIRST/LAST`, `->>`/`@>` в сыром SQL, `::type`, `ON CONFLICT`/`RETURNING`, `CAST AS VARCHAR/TEXT`)
запрещён; вместо него `Core\Support\Database\Sql` (`orderByNullsLast/First`, `whereContainsCi`, `jsonText`, `castText`)
и билдер Laravel (`upsert`, `insertOrIgnore`, `insertGetId`, `'col->key'`, `whereJsonContains`). Веток по
`DB::getDriverName()` в модулях и миграциях нет; код другой СУБД допустим только в разовом `db:transfer-to-mysql` (`backend/app/Modules/Core/Transfer/`), это проверяет CI-страж `scripts/mysql-only-guard.mjs` в job `lint`. Миграции: без
`DEFAULT` у `json/text`, `unique` не на `TEXT`, непрозрачные идентификаторы — `->collation('utf8mb4_bin')`, одна
таблица/индекс на миграцию (DDL в MySQL не транзакционный).

CI: обязательный job `tests` — MySQL 8.4 (`DB_CONNECTION=mysql`, полный PHPUnit + покрытие). Локально БД не поднимаем;
при необходимости ручной проверки — `DB_CONNECTION=mysql DB_URL=mysql://user:pass@127.0.0.1:3306/app_test`.

## Тесты: что локально, что в CI

Правило владельца (02.10.2026): **тяжёлое тестирование выносим в GitHub Actions, локально минимум.**

- **Локально можно:** lint и точечные тесты изменённых файлов — `npx ng test --watch=false --include <файл>`, `phpunit --filter <тест>` (если на машине есть PHP); точечные тесты запускаем, только если среда уже есть на машине, иначе пушим и ждём CI (локальную среду не поднимаем).
- **Только в CI:** полный `ng test`, `npm run e2e`, ui-parity, весь phpunit и phpstan.
- Пушим рано и чиним по логам упавших проверок, а не пытаемся воспроизвести весь набор локально.
- Не держим несколько тяжёлых локальных прогонов параллельно.
- **Исключение:** CI недоступен, или для раздела «Доказ» (verification.md) нужен снимок экрана либо реальный запрос — тогда один точечный прогон, не весь набор.
- Workflows и обязательные проверки это правило не меняет.

## Ночное окно (UTC против Киева)

Workflow `.github/workflows/night-window.yml` (необязательный, не в «Protect main») раз в неделю и по `workflow_dispatch` запускает весь backend phpunit под `faketime -f '@2026-10-15 22:30:00'` — 22:30 UTC = 01:30 Киев следующего дня, когда дата по UTC и по Киеву различается (инцидент 02–03.10.2026: `AssetsApiTest` краснел в окне 21:00–24:00 UTC). Сервер БД живёт на реальном времени — это нормально. Красный прогон — признак места, где код или тест считает дату в UTC вместо `UserTime::today()`/Киева; чиним их, а не перезапускаем.

Страж в обязательном job `backend`: `tests/Unit/Core/UserDayGuardTest.php` падает на новой календарной операции от часов UTC в
`backend/app` (`Carbon::today()`, `now()->startOfDay()`, `$now->toDateString()`, `$now->year` и т. п.). Правило: календарный день,
неделя, месяц, год пользователя — `UserTime::today($now)` / `UserTime::now($now)`, срок «в этот день в 18:00» или «до конца дня» —
`UserTime::wallTime()` / `UserTime::endOfDay()`; момент (created_at, сроки хранения, SLA в часах) остаётся UTC. Осознанный UTC —
строка в `ALLOWED` теста с причиной ([core.md](../modules/core.md)). Тесты ночного окна пишем на три момента:
`Carbon::setTestNow('2026-10-11 21:30:00')` (лето), `'2026-01-11 22:30:00'` (зима), `'2026-12-31 22:30:00'` (смена года).
