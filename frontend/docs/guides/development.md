# Правила разработки (фронтенд)

## Простыми словами
Каждое изменение делается отдельно, проверяется автоматически и ревьюером, и только потом попадает в рабочую версию.
Документация меняется вместе с кодом.

## Процесс
1. Задача → ветка от `master`: `feat/<кратко>`, `fix/<кратко>`, `docs/<кратко>`, `chore/<кратко>`; коммиты — Conventional Commits.
2. Merge-request по шаблону: что, зачем, доказательство (вывод проверок, снимок экрана), документация.
3. Проверки зелёные, независимое ревью, squash-merge в `master`. Прямых пушей в `master` нет (кроме разового разделения проекта).
4. Dockerfile, CI и выкладка живут во внешнем репозитории ci-cd, а не здесь ([deploy.md](deploy.md)).

## Окружение
- Node 24 (`.nvmrc`, `engines`), `npm ci`.
- `npm run dev` — `ng serve` (без бэкенда вход и данные недоступны; поднимите [sinhrm.api](https://gitlabs.itsrv.xyz/dev/sinhrm.api) и направьте `/api` на него dev-прокси или nginx). `npm start` — боевой `server.mjs` поверх готовой сборки; он не проксирует `/api`.
- Вход через Google работает только на домене, зарегистрированном в Google OAuth и в `SANCTUM_STATEFUL_DOMAINS` бэкенда.

## Правила кода
Standalone-компоненты, signals, `inject()`, `OnPush`, typed forms, без `any`; строки интерфейса — только через Transloco (`public/i18n/{ru,uk,en}.json`, все три языка).
**HTTP только через сервисы:** компонент не держит `HttpClient`; HTTP-сервис живёт в самой фиче (`features/<name>/<name>.service.ts` или рядом с подфичей), в `core/api` — только общее: `toParams`, `unwrapData`/`DataEnvelope`, `Paged`, `apiErrorKey`, сервисы ядра. Уведомления — `core/ui/notify.service.ts` ([core.md](../modules/core.md)).
Общие хелперы тестов — `src/testing/` (в сборку не входят): `css(Component)` — скомпилированный CSS для `*.restyle.spec.ts`.
Таблицы со сортировкой и фильтрами в заголовках — [tables.md](tables.md). Правка UI держит UI parity зелёным — [ui-parity.md](ui-parity.md).

## Документация
Изменил модуль → обнови `docs/modules/<модуль>.md` (имя — модуль в kebab-case: `features/hiring-requests` → `hiring-requests.md`). Структура страницы: «Что это и зачем» (просто) → «Как пользоваться» → «Как устроено» (техника) → «Как проверить». Разделы «Что это и зачем» и «Как пользоваться» публикуются в приложении.

### Журнал работ
Каждое изменение добавляет один файл `docs/worklog.d/<YYYY-MM-DD>-<slug>.md` (формат — [worklog.d/README.md](../worklog.d/README.md)); `docs/worklog.md` руками не правим. Хронологию собирает сборка справки («Довідка» → «Журнал работ»); локально — `node scripts/worklog-build.mjs --print`. Номер PR в журнале берётся из squash-коммита и виден только при полной git-истории.

## Проверки
| Что | Команда | Где |
|---|---|---|
| Линт | `npx ng lint` | локально и в CI |
| Юнит-тесты | `npx ng test --watch=false` (точечно: `--include <файл>`; пороги покрытия — `--coverage`, `angular.json` → `test.options.coverageThresholds`) | CI; локально точечно |
| Тесты сервера и справки | `npm run test:docs` | локально |
| Сборка | `npm run build` (справка + `ng build`) | локально и в CI |
| UI parity | `npm run build && npm run e2e`, `npm run e2e:lint` | CI |

Тяжёлые прогоны (полный `ng test`, `npm run e2e`) — в CI; локально — lint и точечные тесты. Проверено 2026-10-13 на Node 24: `ng lint` чисто, `ng test` — 182 файла, 1177 тестов пройдено (1 пропущен), `npm run test:docs` — 9 тестов.
