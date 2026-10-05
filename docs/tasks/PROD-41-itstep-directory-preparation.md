# PROD-41: подготовить интеграцию каталога сотрудников Itstep

## Стан

- Час: 2026-10-05 16:24 Asia/Saigon.
- Worktree / branch / base SHA / last implementation SHA: `D:/Projects/sinhrm-wt/itstep-directory-preparation` / `feat/itstep-directory-preparation` / `38d90eaada1c43363cd64c2f16d7ceaecb611dab` / `f6ed52aa614082bcc52617b67a2384d5cd1d9b9e` (task-state commit follows).
- Текущий шаг: исправить concrete CI/Astra findings; опубликованный draft PR #157 сейчас имеет head `75c1816be796b29935a3898db5be738215854961`.
- Сделано / evidence: CI run `37287955107`: backend tests PASS; api-docs/docs/extension/security/worklog PASS; `lint` FAIL (4 Pint style issues), `frontend` FAIL (Array<T> lint), `ui-parity` FAIL (integration inventory not refreshed and missing `/itstep-directory/status` fixture). Astra exact initial head identified order-dependent duplicate conflict/repetition counting and shared frontend error state. Added order-independent planning plus permutation regression, split status/preview errors into `IntegrationsDirectoryStore` plus reversed completion regression, and added explicit UI parity request/label assertions and synthetic preview state. PHP/Composer and `frontend/node_modules` are unavailable locally. Prior `npm run test:docs`: 2 header tests passed, build-docs test blocked by absent `marked` package. Need update integration inventory snapshots in required workflow (no local node_modules/build currently), fix remaining Pint/test formatting using CI feedback, and rerun full CI + four theme/viewport screenshots.
- Контекст KB: Rovo-запрос `PROD-41 Itstep employee directory SKUD profiles integration SinHRM` завершился HTTP 502; Confluence-запрос `SinHRM SKUD profile API employee directory mapping authentication integration` не нашёл результатов. По инструкции владельца пока нет ссылки на сервис/OpenAPI или подтверждённой схемы.
- Следующий шаг: refresh affected snapshots and complete targeted style/test fixes, push PR revision, run full CI and inspect desktop/mobile light/dark screenshots; then request Astra review of exact final SHA.
- Блокеры: остаются неподтверждёнными owner input: authoritative response schema, подтверждение смысла division/namespace scope, stable canonical employee ID, branch/position/status mappings, trusted service host/auth inputs и установка/инициализация обязательного `itstep/user-client` SDK. Разрешение владельца явно запрещает заменять SDK прямым Illuminate HTTP клиентом. До получения зависимости и контракта не делать source fetch, не включать импорт, не создавать/изменять/назначать роли/деактивировать пользователей и не утверждать подключение к SKUD.

## План

1. Повторно использовать Integration superadmin gate/page patterns, People employee contracts и vault boundary; source identity links сейчас отсутствуют.
2. Добавить typed `EmployeeDirectoryGateway` contract и явный runtime binding в `dependency_pending`, пока `itstep/user-client` не установлен и source contract не подтверждён. Не копировать SDK, не использовать прямой HTTP клиент, не читать credentials и не вызывать service.
3. Реализовать typed normalized-snapshot validation, completeness/namespace/profile ID checks, deterministic source-scoped deduplication, branch/position/status mapping conflicts и read-only identity link plan. Ни одного изменения Employee/User/role/status не выполнять.
4. Выставить superadmin-only локальные SinHRM readiness/live-preview endpoint и отдельно явно помеченный synthetic-preview endpoint; статус `pending/configuration required`, реальные source calls невозможны до approved SDK binding/config.
5. Подключить UI на странице Integrations к этим реальным API SinHRM: видимый `dependency_pending` и отсутствующие contract inputs, синтетический preview/conflict list; TypeScript, ru/uk/en, существующие service/Material patterns.
6. Покрыть реальную runtime wiring/no-network, invalid/unknown/incomplete/duplicate snapshots, deterministic preview/idempotent dedup, superadmin auth и отсутствие PII/secrets в ответах/логах; обновить `docs/modules/integrations.md`, `.http` sample и worklog.
7. Финальные CI, synthetic desktop/mobile UI evidence и независимое Astra review итогового SHA; никаких live API/production/deployment действий.

## Неподтверждённые зависимости

Авторитетный сервисный URL, OpenAPI/schema, tenant/namespace и company scoping, способ получения/хранения bearer token, canonical employee ID и правила преобразования branch/status должны прийти от владельца/сервиса. Сейчас можно подготовить explicit configuration/validation и безопасный preview boundary, но нельзя создавать подставной ответ или считать его доказательством интеграции.
