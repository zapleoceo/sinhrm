# PROD-41: подготовить интеграцию каталога сотрудников Itstep

## Стан

- Час: 2026-10-05 16:55 Asia/Saigon.
- Worktree / branch / base SHA / source SHA: `D:/Projects/sinhrm-wt/itstep-directory-preparation` / `feat/itstep-directory-preparation` / `38d90eaada1c43363cd64c2f16d7ceaecb611dab` / `f006c7261f2cebe3be44c73b739d4450b0f4a0cf` (PR #157).
- Текущий шаг: подтвердить CI исправления PHPStan, затем проверить совмещённый PR156, включающий этот source commit и PR140 launcher.
- Результаты: PR157 CI run `37291833428` на 0726d2f прошёл Pint, но PHPStan обнаружил неподписанный iterable тип `$payload` в `EmployeeDirectoryPreviewTest.php:81`. В f006c72 добавлена PHPDoc-форма `array<string, mixed>`; новый run `37292425236` прошёл все jobs на exact head f006c72. Astra final exact-head review на f006c72 — PASS; reviewer подтвердил PHPDoc-only delta, clean GitHub/local match и 10 зелёных checks. Combined review через PR156 ещё нужен.
- Реализация остаётся безопасной подготовкой: официальный `itstep/user-client` и подтверждённый API schema/namespace/auth/mappings отсутствуют; fetch не выполняется, доступы/роли/профили не меняются. UI synthetic sample явно помечен тестовым.
- KB: Rovo-запрос `PROD-41 Itstep employee directory SKUD profiles integration SinHRM` завершился HTTP 502; Confluence-запрос `SinHRM SKUD profile API employee directory mapping authentication integration` не дал результатов.
- Следующий шаг: PR157 source прошёл; в PR156 дополнительно включён функциональный PR158. Дождаться combined CI/Astra/rendered screenshots; нет live-интеграции и разрешения на merge/deploy.
- Блокер: нужны официальный SDK, сервисный контракт, namespace, canonical ID, mappings и доверенный endpoint/auth config от владельца/сервиса.
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
