# Модуль Perform (1:1, цели OKR, KPI, фидбек, оценка 360, планы развития)

## Что это и зачем
Всё, что помогает человеку расти и понимать, как у него дела, — в одном месте:

- **Встречи 1:1** — регулярный разговор руководителя с сотрудником: повестка (из шаблона или своя), общие заметки,
  **личные заметки руководителя** и договорённости с датами.
- **Цели (OKR)** — цель и 1–10 измеримых ключевых результатов («клиентов подключено: 0 → 10»). Прогресс цели
  считается сам из ключевых результатов; цели связываются в дерево (личная цель → цель команды → цель компании).
  Каждое обновление прогресса («check-in») сохраняется в истории.
- **KPI** — показатель сотрудника за месяц или квартал: план и факт, процент выполнения.
- **Фидбек** — благодарность или конструктив коллеге в любой момент, а также «попросить фидбек».
- **Оценка (performance review / 360)** — цикл оценки по компетенциям и рейтинговой шкале: самооценка, оценка
  руководителя, коллег и подчинённых («снизу вверх»).
- **Планы развития** — цели развития и шаги с датами; сотрудник отмечает выполненные шаги.

**Кто что видит — простыми словами.**
- Админ (он же HR) видит всё.
- Руководитель видит 1:1, цели, KPI, планы и результаты оценки **людей ниже себя** в оргструктуре (как в People).
- Сотрудник видит своё: свои встречи, цели, KPI, планы, фидбек о себе и от себя.
- **Личные заметки 1:1 видит только руководитель, который ведёт встречу.** Их не видит ни сотрудник, ни его
  руководитель выше, ни админ — сервер просто не отдаёт это поле никому другому.
- **Оценки коллег и подчинённых анонимны.** Сотрудник (и его руководитель) видит только среднее по группе, и только
  если оценок в группе **не меньше трёх**. Если коллег оценило двое — группа скрыта целиком: ни цифр, ни
  комментариев. Имена коллег и подчинённых в результатах не показываются никогда (в анонимном цикле).
- Сотрудник видит свои результаты оценки только после **закрытия** цикла.

## Как пользоваться
Меню → раздел **«Продуктивність»**:
- **Цілі (OKR)** (`/perform/objectives`) — выбор квартала, дерево целей с полосками прогресса, «Нова ціль»
  (название, видимость «усім / команді / лише мені й керівникам», родительская цель, ключевые результаты),
  «Оновити прогрес» — новые текущие значения и комментарий.
- **Зустрічі 1:1** (`/perform/one-on-ones`) — слева ближайшие и прошедшие встречи, справа выбранная: повестка
  (галочки), общие заметки, личные заметки (только руководителю встречи), договорённости. Запланировать встречу
  может руководитель со своим подчинённым (админ — любую пару).
- **Фідбек** (`/perform/feedback`) — форма «дать / попросить фидбек», вкладки «Отримані», «Надіслані»,
  «Запити до мене» (кнопка «Відповісти»), «Моя команда» (руководителю), «Публічні».
- **Мої оцінювання** (`/perform/reviews`) — формы, которые нужно заполнить как оценщик; по каждой компетенции уровень
  шкалы и комментарий.
- **Профиль сотрудника → вкладка «Продуктивність»** — цели, KPI (руководитель добавляет), планы развития
  (руководитель создаёт, сотрудник отмечает шаги), 1:1 и результаты оценки (полоски по типам оценщиков).
- **Адміністрування → Цикли оцінювання** (`/admin/perform/reviews`, админ) — шкалы, компетенции, мастер цикла
  (основное → участники и типы → компетенции → создать), «Запустити» (создаются формы), «Завершити».

## Как устроено
Бэкенд — `backend/app/Modules/Perform`, маршруты `/api/perform/*` (`routes.php`), все за `auth:sanctum` +
`EnsureUserIsActive`. Доступ строится на People: `Services/PerformAccess::viewer()` → `DTO/PerformViewer`
(обёртка над `PeopleContext`: `admin()`, `isSelf()`, `isAbove()` — руководитель выше по `manager_id`,
`manages()` = админ или руководитель выше, `sees()` = `manages()` или сам). Gate `perform-manage`
(`Providers/PerformServiceProvider::MANAGE`) = `PeopleScope::isAdmin` — настройка оценки и шаблоны 1:1.
Нет доступа к записи → **404** (запись «не существует» для чужих), нет права на действие → **403**.

### Таблицы (миграция `Database/Migrations/2026_10_04_100001_create_perform_tables.php`)
| Таблица | Колонки | Заметки |
|---|---|---|
| `one_on_one_templates` | `name, agenda jsonb (list<string>)` | запись — админ |
| `one_on_ones` | `manager_employee_id, employee_id, scheduled_at, template_id?, agenda jsonb [{id,text,done}], notes_private_manager?, notes_shared?, action_items jsonb [{id,text,done,due_on?}], status (scheduled\|completed\|cancelled)` | `notes_private_manager` отдаётся только руководителю встречи |
| `objectives` | `scope (personal\|team\|branch\|company), owner_employee_id?, department_id?, branch_id?, period ("2026-Q4"), title, description?, key_results jsonb [{id,title,start,target,current,unit,weight}], progress (0..100), status (active\|achieved\|missed\|cancelled), parent_objective_id?, visibility (public\|team\|private)` | `progress` пересчитывается сервером при каждой записи |
| `objective_checkins` | `objective_id, author_id, progress_before, progress_after, key_results jsonb [{id,current}], comment?` | история прогресса |
| `kpis` | `employee_id, metric, unit?, period ("2026-10" \| "2026-Q4"), target, actual?` | `unique(employee_id, metric, period)` → 409 `duplicate` |
| `feedback` | `from_employee_id, to_employee_id, type (praise\|constructive\|request), text, visibility (private_to_recipient\|manager\|public), request_id?, answered_at?` | запрос всегда `private_to_recipient`; ответ ссылается на запрос |
| `rating_scales` | `name, levels jsonb [{value,label}]` (по возрастанию) | удалить шкалу компетенции — 409 `in_use` |
| `competencies` | `name, description?, scale_id, active` | |
| `review_cycles` | `name, period_start, period_end, participants jsonb {branch_ids, department_ids}, types jsonb [self\|manager\|peer\|upward], competency_ids jsonb, anonymous, deadlines jsonb {type: date}, status (draft\|active\|closed), activated_at?, closed_at?` | правка/удаление — только черновик (409 `cycle_not_draft`) |
| `review_assignments` | `cycle_id, subject_employee_id, reviewer_employee_id, type, status (pending\|submitted), submitted_at?` | `unique(cycle, subject, reviewer, type)` |
| `review_answers` | `assignment_id, competency_id, rating, comment?` | `unique(assignment_id, competency_id)` |
| `development_plans` | `employee_id, title, goals jsonb [{id,text}], actions jsonb [{id,text,due_on?,done}], due_on?, status (active\|completed\|cancelled)` | |

Элементы JSON-списков получают стабильный `id` (`Support/ListItems`: id клиента, если это короткий токен, иначе
сгенерированный) — по нему check-in и отметка шага находят нужный элемент.

### Правила доступа (матрица)
| Сущность | Читать | Создавать / менять |
|---|---|---|
| 1:1 | сотрудник встречи, руководитель встречи, руководители выше сотрудника, админ | создать — руководитель выше сотрудника (`manager_employee_id` = он сам) или админ (любая пара); дата/статус/удаление — руководитель встречи, руководители выше, админ; повестка, общие заметки, договорённости — оба участника; **личные заметки — только руководитель встречи** |
| Цель | `public` — все; `team` — отдел владельца, владелец, руководители выше; `private` — владелец и руководители выше; админ — всё | владелец, руководители выше владельца, админ; цель без владельца и уровня `company` — только админ |
| KPI | сотрудник, руководители выше, админ | руководители выше и админ (сам себе — нет) |
| Фидбек | автор, получатель; `manager` — ещё руководители выше получателя; `public` — все; админ — всё | любой сотрудник (кроме себе, 422 `self_target`); ответ на запрос — только адресат запроса, один раз (409 `request_not_open`) |
| План развития | сотрудник, руководители выше, админ | руководители выше и админ; отметить шаг — и сам сотрудник |
| Форма оценки | только сам оценщик (чужая → 404) | отправить один раз (409 `already_submitted`), каждую компетенцию цикла ровно раз значением её шкалы (422 `invalid_answers`) |
| Результаты оценки | админ и руководители выше — всегда; сам сотрудник — после закрытия цикла | — |

### Прогресс цели (`Support/ObjectiveProgress`)
Ключевой результат: `(current − start) / (target − start)`, ограничено 0..1 (работает и для «уменьшить»,
когда `target < start`); `start = target` → 1, если достигнуто, иначе 0. Цель: взвешенное среднее по `weight`
× 100, округлено. Выравнивание: родитель должен быть видим и не может быть самой целью или её потомком
(422 `alignment_cycle`).

### Оценка 360 (`Services/ReviewSetupService`, `Services/ReviewService`, `Support/ReviewResults`)
**Запуск цикла** создаёт формы по фильтру участников (филиалы/отделы; пусто = все не уволенные) и типам:
`self` — сам; `manager` — его руководитель (если работает); `peer` — до 5 коллег с тем же руководителем;
`upward` — его прямые подчинённые. Админ может добавить оценщика вручную (`POST …/assignments`).
**Результаты** (`ReviewResults::aggregate`): среднее по компетенции для каждого типа оценщиков; `average` —
среднее без самооценки. Группы `peer` и `upward` защищены: меньше `MIN_REVIEWERS = 3` оценщиков → `suppressed`,
без числа, без количества, без комментариев; в анонимном цикле у их комментариев нет автора, и комментарии
отсортированы по тексту (порядок отправки мог бы выдать человека). Список «кто кого оценивает» админ видит
(статусы), ответы — нет: эндпоинта с ответами одного оценщика не существует.

### Эндпоинты (`/api/perform/...`)
| Метод и путь | Кто | Что |
|---|---|---|
| `GET one-on-ones?employee_id&status`, `GET/PATCH/DELETE one-on-ones/{id}`, `POST one-on-ones` | см. матрицу | 1:1; ответ: `can_manage`, `can_private_notes`, ключ `notes_private_manager` только руководителю встречи |
| `GET one-on-one-templates`; `POST/PUT/DELETE one-on-one-templates[/{id}]` | все; запись — админ | шаблоны повестки |
| `GET objectives?period&owner_employee_id`, `GET objectives/{id}` (с `checkins`), `POST`, `PUT objectives/{id}`, `DELETE`, `POST objectives/{id}/check-ins {key_results:[{id,current}], comment?}` | см. матрицу | цели |
| `GET kpis?employee_id&period`, `POST kpis`, `PUT/DELETE kpis/{id}` | см. матрицу | `attainment` = факт / план, % |
| `GET feedback?box=received\|given\|requests\|team\|public`, `POST feedback {to_employee_id \| request_id, type, text, visibility?}` | все | `team`: руководителю — `manager`+`public` о людях ниже, админу — всё |
| `GET development-plans?employee_id`, `POST`, `PUT/DELETE development-plans/{id}`, `PATCH development-plans/{id}/actions/{actionId} {done}` | см. матрицу | `progress {done,total}` |
| `GET review/assignments`, `GET review/assignments/{id}`, `POST review/assignments/{id}/submit {answers}` | оценщик | свои формы |
| `GET review/cycles/{cycle}/results/{employee}`, `GET review/employees/{employee}/results` | см. матрицу | агрегаты `ReviewResults` |
| `GET/POST/PUT/DELETE review/scales`, `review/competencies`, `review/cycles`, `POST review/cycles/{id}/activate\|close`, `POST review/cycles/{id}/assignments` | админ (`perform-manage`) | настройка |

Ошибки бизнес-правил — `Exceptions/PerformException` → `{message, code}`: `no_employee`, `self_target`,
`alignment_cycle`, `request_not_open`, `cycle_not_draft`, `cycle_not_active`, `already_submitted`,
`invalid_answers`, `in_use`, `duplicate`.

### Слои
Контроллеры (`Http/Controllers`, база `PerformController` — пользователь и `PerformViewer`) → FormRequest
(`Http/Requests`, метод `payload()`) → сервисы (`OneOnOneService`, `ObjectiveService`, `KpiService`,
`FeedbackService`, `DevelopmentPlanService`, `ReviewSetupService`, `ReviewService`) → репозитории-интерфейсы
(`Contracts/*Repository`, реализации `Repositories/Eloquent*`, привязка в провайдере). Чистые калькуляторы без БД —
`Support/ObjectiveProgress`, `Support/ReviewResults`, `Support/ListItems`.

### Фронтенд (`frontend/src/app/features/perform`)
| Файл | Что |
|---|---|
| `perform.model.ts` | типы API и чистые функции: `quarterOf`, `keyResultRatio` (как на сервере), `objectiveTree` (дерево по `parent_objective_id`, невидимый родитель → корень, защита от циклов), `splitMeetings`, `itemsBody`, `parseIds` |
| `perform.service.ts` | HTTP-клиент `/api/perform/*`, `performErrorKey` |
| `one-on-ones/`, `objectives/`, `feedback/`, `reviews/my-reviews.page.ts` | страницы раздела «Продуктивність» |
| `reviews/review-admin.page.ts` | `/admin/perform/reviews`: шкалы, компетенции, мастер цикла (`mat-stepper`), запуск/закрытие |
| `reviews/review-results.ts` | результаты: полоски CSS по типам оценщиков, «приховано: менше N оцінювачів» |
| `profile/performance.tab.ts` | вкладка профиля «Продуктивність» |

## Как проверить
- `php artisan test --filter=Perform` — Feature: `OneOnOnesTest` (личные заметки не уходят никому, кроме
  руководителя встречи; права по полям), `ObjectivesTest` (прогресс, check-in, циклы выравнивания, видимость),
  `KpisAndPlansTest`, `FeedbackTest` (видимость, запрос → ответ один раз), `ReviewsTest` (генерация форм, валидация,
  **группа коллег из 2 скрыта, из 3 — показана без имён**, сотрудник видит результаты после закрытия);
  Unit: `tests/Unit/Perform/CalculatorsTest`.
- Фронт: `npx ng test` — `perform.spec.ts`.
- Вручную на preview: руководитель → 1:1 с подчинённым, личная заметка → войти подчинённым: заметки нет.

## Вопросы и следующие шаги
- Номинация коллег для 360 самим сотрудником (сейчас — автоматически коллеги одного руководителя + вручную админом).
- 9-box (потенциал × результат) и калибровка — не сделаны.
- Синхронизация 1:1 с Google Calendar — после подключения Google (модуль GoogleWorkspace умеет создавать события).
- Выбор сотрудника в формах по ID — временно; нужен общий компонент поиска сотрудника.
