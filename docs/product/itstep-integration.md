# SinHRM — подготовка приложения к Itstep

## Решение владельца

05.10.2026: целевой production переносится на инфраструктуру Itstep. Владелец является superadmin. Команда приложения поставляет готовые frontend/backend и интеграцию сотрудников; DevOps настраивает серверы, production резервные копии, восстановление, секреты окружения и alerts. Требование «как в Sintegrum» изучается по фактическому протоколу, а не по названию интеграции.

## Что установлено по Sintegrum

Два независимых агента проверили локальные исходники API и frontend. Проверка относится к API commit `c70243a` и frontend `9e12132`; это не live проверка сервисов. Секреты и реальные сотрудники не запрашивались.

| Механизм | Найденное поведение | Граница доказательства |
|---|---|---|
| Вход | Компания определяется alias; `/v1/<alias>/auth/login` проверяет локальный пароль по email/телефону; выдаются отдельные случайные access/refresh tokens | Это не доказательство корпоративного SSO и не готовый контракт для Laravel |
| Найм | При переходе кандидата в сотрудника для Itstep отправляется `UserSyncDto` в общий user service; передаются branch/position и связь `ExternalTypes::SINTEGRUM` с локальным User ID | Исходящий найм не является импортом всего штата |
| Справочники | `/skud/branches` получает каталог филиалов; `/skud/positions` получает должности для email текущего пользователя | Эти endpoints не предоставляют полный источник сотрудников |
| Связь филиала | Диалог найма предварительно выбирает внешний филиал по совпадению trimmed name; каталог возвращает также uuid | Не копировать сравнение названий как гарантированную стабильную связь |
| Идентификаторы | Локальный Sintegrum User ID, общий USER-service ID и InsightTrack ID имеют разные назначения | Canonical SKUD profile ID в исследованных файлах не установлен |
| Экспорт пользователей | Локальный directory endpoint Sintegrum поддерживает пагинацию и фильтры | Это локальный справочник Sintegrum, не доказанный canonical SKUD feed |

Источники API: `modules/v1/config/rules.php`, `company/controllers/auth/LoginAction.php`, `company/models/forms/LoginForm.php`, `company/services/TokenService.php`, `company/components/behaviors/SyncBehavior.php`, `company/controllers/skud/BranchesAction.php`, `PositionsAction.php`, `company/commands/UserExternalIdController.php`, `config/components/userStorage.php`. Пути company относительны `modules/v1/modules/`. Frontend: `src/app/auth/auth.service.ts`, `shared/services/skud/skud.service.ts` и `main/modules/users/user/hire-user-dialog/hire-user-dialog.component.ts`, последние два относительно `src/app/`.

Поиск внутренней KB вернул HTTP502. Отсутствие KB не блокировало исследование исходников. Реализация зависимости `itstep/user-client` отсутствует в локальном vendor, поэтому точные upstream HTTP/queue DTO и полный employee feed ещё требуют источника. Эти неизвестные поля не считаются дефектом уже существующего SinHRM.

### Дополнительная проверка внутренних SDK

Репозитории SDK удалось прочитать через существующий HTTPS доступ; SSH publickey access отказал. Проверены текущие `user-client`43258e6/`api-client`89db503 и точные версии Sintegrum lock: `user-client`1.4.24@29578fb, `api-client`1.5.2@f13ac43, без изменения checkouts или обращения к реальным сотрудникам.

Подтверждён `ProfileResource::index`: `GET /api/v1/profiles` с `user_id`, `login`, `type`, `status`, `project_id`, `branch_id` и page/per_page; default page1/per_page20, sort id DESC. SDK перечисляет employee и статусы active/blocked/deleted. `division_id` появился в текущем SDK, но отсутствует в исследованной locked версии — переносить его без проверки версии нельзя. Transport поддерживает Bearer и GET query fields. Эти факты подтверждены исходниками SDK, но не live поведением сервера.

`ProfileResponse` остаётся generic JSON: серверный response envelope, pagination termination/total и ACL этим не установлены. Factory показывает profile.id/user_id, memberships-related поля и externals, но не гарантирует их canonical SKUD семантику. Cursor/updated-since/deletion watermark не найден; token issuance/renewal и корпоративный SSO SDK не задаёт. Read-only доступ к referenced owning service пока не подтверждён. Поэтому page enumeration найдено, а полную корректную синхронизацию ещё нельзя объявить готовой.

## Контракт, который нужно установить до adapter implementation

1. Какой сервис удостоверяет вход: локальные credentials, корпоративный identity endpoint или отдельный SSO. Нужны схема запроса/ответа, expiry/refresh/revoke, immutable identity и company scope.
2. Какой сервис является источником всех сотрудников: стабильный profile ID, memberships, branch/department/position IDs, inactive/deleted states, пагинация или delta/cursor, ошибки и повторная доставка.
3. Как связывать существующие SinHRM user/employee с внешними identities и какие начальные права получает импортированный сотрудник. Superadmin владельца подтверждён; права остальных нельзя выводить из совпавшего email или домена.

Владелец подтвердил 05.10.2026: актуальной ссылки/документации пока нет; записать это в долги и подготовить код, остальное предоставит позже. Поэтому разрешена подготовка typed gateway, выключенного по умолчанию подключения и read-only preview/conflicts с явной схемой/namespace. Неизвестная схема или неполная выдача не меняют operational employees/ACL. Live import и corporate SSO не считаются готовыми до получения и проверки контракта.

Сначала ищем существующий контракт/SDK в доступных репозиториях и документации; обращаемся к владельцу только с конкретным нерешённым выбором. Значения токенов в публичный репозиторий и чат не переносятся.

## Критерии интеграции приложения

- Отдельные identity и employee-directory gateways с DI; Laravel user/roles не подменяются моделями Yii2.
- Source-scoped внешние IDs и явная связь с внутренними user/employee. Дубликаты записываются как конфликт, а не молча объединяются по email.
- Повторный импорт не создаёт дубликаты; изменения статуса/структуры применяются по согласованному контракту. Недоступный directory не означает удаление или увольнение всех сотрудников.
- Внешняя личность не выдаёт ACL сама по себе. Отзыв доступа покрывается существующим credential-generation контрактом, включая stale grants после block/unblock.
- Пользователь видит результат синхронизации и ошибки без токенов и чужих персональных данных. Тесты используют синтетические identities и повторные/ошибочные ответы.
- Полная интеграция принимается после разрешённой проверки реального сервиса; fixtures/успешный CI не объявляются production подключением.

## Поставка DevOps

PROD-39 должен проверить и описать текущие версии PHP/Node, сборку frontend/backend, старт web/API, migrations, worker/scheduler, persistent uploads, health/readiness и список обязательных переменных без значений. Проверить Vercel-specific entrypoint/routing/runtime assumptions и подготовить независимый способ запуска там, где это действительно нужно.

Здесь ещё нет утверждённой runtime инструкции или готового adapter: этот документ фиксирует доказанный исходный контракт, требования и последовательность работ. Production настройку выполняют DevOps после передачи проверенного приложения.
