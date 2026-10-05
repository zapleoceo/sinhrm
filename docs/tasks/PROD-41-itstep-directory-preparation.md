# PROD-41: подготовить интеграцию каталога сотрудников Itstep

## Стан

- Час: 2026-10-05 16:04 Asia/Saigon.
- Worktree / branch / base SHA / last implementation SHA: `D:/Projects/sinhrm-wt/itstep-directory-preparation` / `feat/itstep-directory-preparation` / `38d90eaada1c43363cd64c2f16d7ceaecb611dab` / `f6ed52aa614082bcc52617b67a2384d5cd1d9b9e` (task-state commit follows).
- Текущий шаг: отправить draft PR, запустить полный CI и получить rendered light/dark/mobile evidence и точный Astra review.
- Сделано / evidence: implementation commit `f6ed52aa614082bcc52617b67a2384d5cd1d9b9e`; до коммита прошли `git diff --cached --check` и Node JSON parse uk/ru/en. `npm run test:docs` был запущен: два header tests прошли, build-docs test не стартовал из-за отсутствующего package `marked`, общий exit failure. PHP/Composer и `frontend/node_modules` отсутствуют, поэтому backend/PHPStan/Pint и Angular tests/build локально не выполнялись. Private SDK проверка подтверждает request path/filters, но не wire schema; `itstep/user-client` отсутствует, Employee не имеет source identity link и employee mappings не утверждены. Реализованы typed gateway с fail-closed `dependency_pending`, explicit namespace equality check, строгая canonical snapshot validation, deterministic dedup/conflict planner с read-only manual identity review, superadmin status/live-preview/synthetic-preview API и Integrations UI. Все операции чтения; employee/user/role writes отсутствуют. Synthetic fixture помечен, содержит exact duplicate и conflicting ID; новая backend/frontend coverage и docs/worklog добавлены.
- Контекст KB: Rovo-запрос `PROD-41 Itstep employee directory SKUD profiles integration SinHRM` завершился HTTP 502; Confluence-запрос `SinHRM SKUD profile API employee directory mapping authentication integration` не нашёл результатов. По инструкции владельца пока нет ссылки на сервис/OpenAPI или подтверждённой схемы.
- Следующий шаг: запустить обязательный полный CI на опубликованном final SHA, исправить найденные ошибки и проверить desktop/mobile rendered evidence.
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
