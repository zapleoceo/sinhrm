# Ревью архитектуры SOLID/DRY — октябрь 2026

## Простыми словами
Проект проверили на повторяющийся код (DRY) и на принципы SOLID: у каждого класса одна обязанность, модули зависят
друг от друга через интерфейсы (контракты), а не через чужие внутренности. Найденное исправлено тремя PR без изменения
поведения: те же адреса API, те же ответы и ошибки, те же экраны (кнопки, поля, колонки). Ниже — что измерено до и
после, что сделано и что оставлено сознательно, с причиной.

| PR | Область | Ветка |
|---|---|---|
| #181 | бэкенд: репозитории и контракты модулей (SRP, DIP, границы модулей) | `refactor/solid-dry-be-dip` |
| #182 | бэкенд: общие каркасы, разгрузка крупных сервисов, enum вместо строк (DRY, SRP) | `refactor/solid-dry-be-dry` |
| фронтенд-PR | фронтенд, стили, расширение (DRY, SRP, мёртвый код) | `refactor/solid-dry-frontend` |

## Как мерили
- **Дубли** — jscpd 4, `--min-tokens 50`: бэкенд `backend/app` (php), фронтенд `frontend/src/app` (typescript без
  `*.spec.ts`; markup), стили `frontend/src` (scss), расширение `extension/src`. Встроенные стили компонентов
  (блоки `styles:` в `.ts`, 116 штук) извлекались в отдельные файлы и проверялись jscpd с `--min-tokens 40`.
- **Мёртвый код** — knip 5, точки входа: `src/main.ts`, все `*.spec.ts`, `src/testing`, `e2e`, `scripts`
  (иначе экспорт, который нужен одному тесту, считался бы мёртвым).
- **Границы модулей** — `backend/tests/Unit/Core/ModuleBoundariesTest.php` и его baseline
  (`module-boundaries-baseline.php`): импорт чужих `Models`/`Services`/`Repositories`/`Http`.
- **SQL вне репозиториев** — поиск `::query()`, `::where(`, `::find(`, `DB::` в `Http/Controllers`, `Services`, `Support`.
- **Циклы импорта фронтенда** — madge 8 `--circular`.
- «До» — `origin/main` на 2026-10-08 (`a84d15e4`, затем пересчитано на `2b058c9a` с #178–#180: те же числа),
  «после» — HEAD веток; для бэкенда «после» — пробное слияние #181 и #182 (конфликтов нет).

## Метрики до и после

| Метрика | До | После |
|---|---|---|
| Бэкенд: клоны jscpd / дублированные строки | 134 / 1473 из 75 981 (1.94%) | 101 / 1047 из 76 722 (1.36%) |
| Фронтенд TS: клоны / строки | 86 / 768 из 43 534 (1.76%) | 48 / 376 из 44 311 (0.85%) |
| Фронтенд HTML: клоны | 2 (18 строк) | 2 (18 строк) |
| SCSS-файлы: клоны | 2 (15 строк) | 0 |
| Встроенные стили компонентов: клоны | 3 (23 строки) | 0 |
| Однострочные копии `.small`/`.spacer` во встроенных стилях | 31 | 7 (другие значения) |
| Расширение: клоны | 0 | 0 |
| Нарушения границ модулей (baseline) | 255 (Models 159, Services 83, Http 11, Repositories 2) | 188 (Models 155, Services 20, Http 11, Repositories 2) |
| Двусторонние зависимости модулей (`KNOWN_CYCLES`) | 7 | 7 |
| Контроллеры с запросами к БД | 3 | 0 |
| Сервисы/Support с запросами к БД (кроме демо-данных) | 12 файлов | 1 (`Recruiting/Support/ApplicationVisibility`) |
| PHP-файлы > 300 строк | 7 | 7 (см. «Оставлено») |
| knip: неиспользуемые экспорты (символов) | 81 (вне `assistant` — 44) | 37 (вне `assistant` — 0) |
| knip: неиспользуемые экспортированные типы | 56 (вне `assistant` — 28) | 46 (вне `assistant` — 18) |
| Циклы импорта фронтенда (madge) | 1 (`assistant/mascot`) | 1 (`assistant/mascot`) |

Крупные классы, разгруженные по SRP (строк до → после):

| Файл | До | После | Что вынесено |
|---|---|---|---|
| `HiringRequests/Services/HiringRequestService.php` | 464 | 355 | `RouteSnapshot`, `RequestAttributes`, `VacancyDraft`, `HiringProgress` |
| `Pulse/Services/ResponseService.php` | 440 | 387 | `Support/WaveComparison` (чистая арифметика сравнения волн) |
| `Ai/Services/AiService.php` | 340 | 311 | `AiGate` (ворота доступа), `AiBudget` (дневные лимиты) |
| `TimeOff/Services/LeaveRequestService.php` | 323 | 303 | `LeaveNotifications` (письма о заявках) |
| `features/people/org-chart/org-chart.page.ts` | 1019 | 969 | `org-export.ts` (PNG/SVG), `org-keys.ts` (клавиатура) |
| `features/recruiting/vacancies/vacancy-form.page.ts` | 510 | 462 | `vacancy-form.body.ts` (тело запроса, разбор 422) |
| `features/recruiting/board/board.page.ts` | 573 | 574 | правило переноса карточки → `BoardStore.planMove` (страница не похудела, правило стало тестируемым) |

## Что сделано

### Бэкенд, #181 — репозитории и контракты
- Запросы к БД из контроллеров и сервисов — в репозитории модуля: `Observability` (`ErrorEventRepository`: список,
  upsert по отпечатку, очистка), `Privacy` (`PrivacyRepository`; транзакция обезличивания — в сервисе, это единица
  работы над провайдерами всех модулей), `Recruiting` (шаблоны вакансий, личная доска `PersonalBoardRepository`,
  офферы, страница карьеры, справочники для AI-черновика), `People` (история компенсаций), `Auth` (`UserRepository`
  для поиска пользователей другими модулями).
- Контракты модулей-владельцев вместо чужих сервисов (узкие интерфейсы — ровно те методы, что зовут снаружи):
  `People\Contracts\PeopleAccess`, `EmployeeLookup`; `Scripts\Contracts\TaskScheduler`, `TaskReader`;
  `Ai\Contracts\AiGateway` (его реализует один `AiService` — правило 7 сохраняется); `Recruiting\Contracts\RecruitingAccess`,
  `CandidateIntake`, `TouchpointLogger`; `GoogleWorkspace\Contracts\GoogleConnections`;
  `Integrations\Contracts\IntegrationConfigs`, `IntegrationSettings`; `Audit\Contracts\AuditHistory`;
  `Documents\Contracts\DocumentTemplateRepository` для шага воркфлоу «создать документ».
- Импорты чужих `Services` 83 → 20, baseline 255 → 188; [overview.md](../architecture/overview.md) обновлён.

### Бэкенд, #182 — общие каркасы и SRP
- Отчёты: `AbstractRecruitingReport`, `AbstractTeamReport`, `AbstractTimeReport`, `AbstractLeaveReport`,
  `AbstractBucketReport` вместо повторяющегося каркаса ~20 определений; вывод отчётов тот же.
- Интеграции: `AbstractHttpCheckedDefinition::probe()` — общая HTTP-проверка подключения (SSRF-защита
  `OutboundUrlGuard`, таймаут 10 с, без редиректов) для AI Broker, Telegram Business, Viber, WhatsApp Cloud.
- Воркфлоу: `ProfileTaskExecutor` для шагов «задача по профилю» (`create_task`, `assign_buddy`, `request_form`).
- Исключения: `Core\Exceptions\BusinessRuleException` — общий конструктор и JSON ошибки для 19 модульных исключений.
- Валидация: трейт `Core\Http\Requests\Concerns\HasSubjectAndBody` для Desk и SafeSpeak.
- AI: общий разбор ответа брокера `{job_id}` (`AiBrokerProvider::jobRef`) для чата и аудио; промпты и их версии не тронуты.
- Строки ролей и статусов → enum: `UserRole::Admin/Superadmin` в `ApproverNotifier`, `UserStatus::Active` в правилах
  валидации Recruiting, `ApplicationStatus::Active` в ранжировании скрининга.

### Фронтенд, стили, расширение
- `core/ui/table/paged-list.ts` (`PagedList`) поверх готового `LatestRequest`: сигналы `items/total/loading/failed` и
  `load()` с отменой предыдущего запроса. Заменил ручные счётчики `seq` в 8 сторах и одинаковые `load()` 17 страниц.
- `TableUrlState.setPage/setSort/setFilter`, `idToFilter` — общие обработчики серверных таблиц (audit, users,
  directory, people).
- `core/ui/with-member.ts` (обновление `Set`, 8 копий), `core/ui/event-value.ts` (значение поля из события, 15 копий),
  `core/date/iso-day.ts` (UTC-день `YYYY-MM-DD` из timeoff и time; отделён от `iso-date.ts`, где локальная дата).
- `core/auth/auth.model.ts`: `ADMIN_ROLES`, `isAdmin`, `SUPERADMIN_ROLE` вместо копий `'superadmin' || 'admin'`
  в маршрутах и проверках доступа.
- Уведомления — через `NotifyService` (21 прямой вызов `MatSnackBar` убран).
- `reports/report-run.ts` (запуск отчёта и CSV), `people/profile/dialog-save.ts` (сохранение диалогов профиля).
- Стили: глобальные `.small`, `.spacer`, `.rows` в `styles.scss` (удалены локальные копии с идентичным значением),
  Sass-миксины `core/ui/styles/_sortable-items`, `_service-panel`, `_trace` вместо копий в редакторах, панелях
  подключения и шести полосах прогресса.
- knip: сняты лишние `export`, удалены неиспользуемые `monthKey`, `OBJECTIVE_SCOPES/STATUSES`, свои копии `Paged` и
  реэкспорт `toParams` (берутся из `core/api`); в расширении удалена неиспользуемая `getLang()`.

## Оставлено сознательно и почему

| Что | Почему не меняли |
|---|---|
| Дубли в миграциях (`Database/Migrations`) | миграции — история схемы: правка применённой миграции не меняет базу, а ломает сверку с уже развёрнутыми окружениями |
| Объявления Eloquent-моделей (`$fillable`, `$casts`, блоки `use`) | декларативный шаблон фреймворка; «общий родитель» ради одинаковых полей связал бы несвязанные сущности |
| Импорты чужих `Models` в Eloquent-связях (155) | `belongsTo`/`hasMany` требуют класс модели; замена на контракты — отказ от связей Eloquent во всём проекте, это решение уровня ADR, а не рефакторинг |
| 7 двусторонних зависимостей модулей | у каждой своя причина в `KNOWN_CYCLES` (демо-сид Core, история Audit, отправка оффера через Channels); разрыв требует переноса событий/данных между модулями и меняет поведение |
| 20 оставшихся импортов чужих `Services` | 8 — определения Reports читают отчётные сервисы Recruiting, Scripts, Time, Pulse (контракт повторил бы их API целиком; это отчётный слой над модулями); 4 — демо-сид Core (см. ниже); 2 — `Auth\Services\PersonalTokens` в сервисах токенов MCP и расширения; `OfferService` → `Channels\MessageService` — часть цикла Channels ↔ Recruiting; `CollectAssetsExecutor` → `AssigneeResolver`, `CreateDocumentExecutor` → `DocumentService`, `HiringRequestService` → `VacancyService` — одиночные вызовы с записью, контракт для них — отдельный PR модуля. `ApproverNotifier` → `TaskService` и Pulse `ResponseService` → `PeopleScope` переводятся на `TaskScheduler`/`PeopleAccess` после слияния #181 и #182: эти файлы меняли обе ветки, перевод в одной из них дал бы конфликт |
| `Core/Services/Demo/DemoDataService.php` (1038 строк), `Recruiting/Services/RecruitingDemoData.php` (439) | генераторы демо-данных пишут в таблицы напрямую ради скорости и независимости от бизнес-правил; разрез по модулям меняет порядок и состав сида, а ui-parity и демо-стенд опираются на него |
| `Core/Transfer/Preflight.php` (303) | перенос PostgreSQL → MySQL — зона отдельной задачи (ADR 0011), код живёт до конца миграции данных |
| `Recruiting/Support/ApplicationVisibility` (подзапрос `DB::table('vacancies')`) | это построитель области видимости, который встраивается в чужие запросы как scope, а не самостоятельный запрос |
| Транзакции `DB::transaction` и `Log` в сервисах | единица работы и журнал — обязанность сервиса; перенос транзакции в репозиторий разорвал бы её на части |
| `SafeSpeakException` вне `BusinessRuleException` | отдаёт `Retry-After` в заголовке и строится иначе; общий конструктор потерял бы заголовок |
| `features/assistant/*` (knip: 37 экспортов, 28 типов; madge: 1 цикл в `mascot`) | модуль параллельно меняет ветка ассистента; правка здесь дала бы конфликты. Экспорты в `mascot` — настройки анимации, которые читают спеки движка |
| 18 публичных типов `core` (`NotifyOptions`, `ClientTableOptions`, `LiveEditOptions` и др.) и `*.model.ts` | контракт общих помощников для фич: тип параметра нужен вызывающему коду, даже если сейчас его объявляет сам помощник |
| `.num`, `.list`, `.error` глобальными не сделаны | у части страниц то же имя с другим значением (два цвета ошибки: `--app-danger` и `--app-bad-text`); глобальное правило изменило бы вид. Единый цвет ошибки — визуальное решение для дизайна |
| 7 локальных `.small`/`.spacer` | у них другие значения (0.6875–0.875rem, отступы) |
| HTML-дубль `audit.page.html` ↔ `users.page.html` (13 строк) | общий компонент добавил бы host-элемент в DOM и изменил инвентарь ui-parity; разметка короткая |
| Повторы блоков `import` и декораторов `@Component` | jscpd считает их клонами, это синтаксис Angular, а не логика |
| `column-header.ts` (539), `profile.page.ts`, `people.page.ts`, `ai-panel.ts` | после выноса помощников остаток — шаблон и связывание с ним; дальнейший разрез переносит разметку в дочерние компоненты и меняет DOM |

## Как проверить
- CI каждого PR: `backend` (pint, phpstan, PHPUnit на MySQL 8.4 с покрытием), `frontend` (lint, Vitest, сборка),
  `ui-parity` (инвентарь и снапшоты без изменений), `extension`, `docs`, `worklog`.
- Повторить замеры: jscpd и knip с параметрами из «Как мерили»; границы модулей —
  `php vendor/bin/phpunit --filter ModuleBoundariesTest`.
- Новые нарушения не появятся незаметно: baseline границ может лишь сокращаться, а общие помощники описаны в
  [core.md](../modules/core.md) и [tables.md](tables.md).
