# Модуль Desk (обращения сотрудников в HR, SLA)

## Что это и зачем
Сотрудник задаёт вопрос HR («где мой расчётный лист», «нужна справка», «сломался пропуск») не в личной переписке, а
**обращением**: с категорией, темой, текстом и файлами. HR видит все обращения в одной очереди, отвечает в ветке,
пишет **внутренние заметки** (сотрудник их не видит), прикладывает статьи из [базы знаний](knowledge.md).
У каждой категории есть **SLA** — за сколько часов HR должен впервые ответить и за сколько решить. Просрочки видны
значком, а раз в ~30 минут система ставит задачу «SLA порушено» ответственному.

**Кто что видит:** сотрудник — только свои обращения и публичные ответы; админ (HR) — всё: очередь, внутренние
заметки, назначение, статусы, категории; руководитель как руководитель обращений подчинённых **не видит** (обращение
может быть о нём). Чужое обращение — 404.

## Как пользоваться
- **Сервіси → Мої звернення** (`/desk`): «Нове звернення» — категория, тема, текст; затем страница обращения
  (`/desk/cases/:id`): ветка, ответ, «Прикріпити файл» (PDF, PNG, JPG, DOCX до 2 МБ), «Закрити звернення».
- **Адміністрування → Черга звернень** (`/desk/queue`): открытые обращения (или по статусу) со значком SLA
  (красный — нарушено, жёлтый — идёт, серый — выполнено/нет цели); внизу — категории и часы SLA, выключение категории.
- В обращении HR: статус (`new` → `in_progress` → `waiting` → `resolved` → `closed`, можно переоткрыть), «Взяти собі»,
  галочка «Внутрішня нотатка», выбор статьи базы знаний к ответу.
- Задачи о просрочке — в «Мої задачі», источник «Звернення» (`desk`).

## Как устроено
- Счётчик в меню ([shell.md](shell.md), `GET /api/nav/badges`, [core.md](core.md)): `Services/DeskNavBadges` — ключ `desk_mine` («Мої звернення»): мои обращения в статусе «Очікує відповіді» (`waiting`, HR ждёт ответа от меня); ключ `desk_queue` («Черга звернень», только HR с правом `desk-manage`): открытые обращения — как фильтр очереди по умолчанию. Оба числа — один `count(*)` (`DeskService::countMine/countQueue`, общий с `cases()` построитель запроса).
Бэкенд — `backend/app/Modules/Desk`, маршруты `/api/desk/*` (`routes.php`), все за `auth:sanctum` +
`EnsureUserIsActive`; gate `desk-manage` = `PeopleScope::isAdmin` (очередь, категории).

### Таблицы (`Database/Migrations/2026_10_05_200001_create_desk_tables.php`)
| Таблица | Колонки | Заметки |
|---|---|---|
| `desk_categories` | `name, first_response_hours?, resolve_hours?, default_assignee_id?, active` | часы от открытия; `null` — без цели; удаления нет — выключение |
| `desk_cases` | `employee_id, category_id, subject, body, status, assignee_id?, first_response_at?, resolved_at?, closed_at?, created_by?` | `status`: `new\|in_progress\|waiting\|resolved\|closed` |
| `desk_comments` | `case_id, author_id?, body, internal, article_id?` | `internal` — только HR; `article_id` — опубликованная статья Knowledge |
| `desk_attachments` | `case_id, uploaded_by?, filename, mime, size, sha256, content (base64)` | ≤ 10 файлов на обращение; `content` скрыт от сериализации |

### API
| Метод и путь | Кто | Что |
|---|---|---|
| `GET /api/desk/categories` (`?all=1` — HR, с выключенными) | все | категории |
| `POST /api/desk/categories`, `PATCH …/{id}` | HR | категория и SLA (часы 1..2160); ответственный по умолчанию — только HR-пользователь (422 `invalid_assignee`) |
| `GET /api/desk/cases/mine` | все | свои обращения (нет карточки сотрудника — пусто) |
| `GET /api/desk/cases?status=&category_id=&assignee_id=&open=1` | HR | очередь |
| `POST /api/desk/cases` `{category_id, subject, body}` | сотрудник с карточкой | 201; без карточки — 422 `no_employee`; выключенная категория — 422 |
| `GET /api/desk/cases/{id}` | автор, HR | детали: ветка (автору — без внутренних), файлы, SLA |
| `PATCH /api/desk/cases/{id}` `{status?, assignee_id?, category_id?}` | HR; автор — только `status: closed` | иначе 403 |
| `POST /api/desk/cases/{id}/comments` `{body, internal?, article_id?}` | автор (без `internal`/`article_id` — иначе 403), HR | закрытое — 409 `case_closed`; черновик статьи — 422 `article_not_found` |
| `POST /api/desk/cases/{id}/attachments` (multipart `file`) / `GET …/attachments/{a}` | автор, HR | загрузка / скачивание (`attachment`, `nosniff`, `no-store`) |

### SLA (`Support/Sla`, чистая функция)
`first_response_due = created_at + first_response_hours`, `resolve_due = created_at + resolve_hours`. Цель **нарушена**,
если событие случилось позже срока, или ещё не случилось, а срок прошёл. Первая **публичная** реплика HR ставит
`first_response_at` (внутренняя — нет) и переводит `new` → `in_progress`; ответ автора в `waiting` → `in_progress`.
`resolved`/`closed` ставят `resolved_at` (часы решения останавливаются); переоткрытие обнуляет `resolved_at/closed_at`
(отсчёт снова от открытия). Флаги считаются при каждом чтении — не хранятся.

### Задача `desk.sla` (`Services/DeskSlaJob`, `Core\Contracts\ScheduledJob`)
Каждый вызов `POST /api/ops/jobs/run`: открытые обращения категорий с SLA → для каждой нарушенной цели задача типа
`desk_sla` («SLA порушено (перша відповідь|вирішення): звернення #N», ссылка `/desk/cases/N`) ответственному, иначе
ответственному категории, иначе первому активному админу. Идемпотентно: ключ `desk:<id>:first_response|resolve` —
одна задача на обращение и цель (`TaskService::schedule`). Ответ: `{sla_breaches, sla_unassigned}`.

### Файлы — переиспользование Documents
Загрузка валидируется тем же `Documents\Http\Requests\UploadDocumentFileRequest` (pdf/png/jpg/docx, ≤ 2 МБ), тип
проверяется по байтам `DatabaseDocumentStorage::detect`, имя — `safeName`, лимит — `DocumentStorage::MAX_BYTES`.
Хранятся в своей таблице (`DocumentStorage` привязан к модели документа).

### Фронтенд
`frontend/src/app/features/desk`: `my-cases.page` (`/desk`), `case.page` (`/desk/cases/:id`), `queue.page`
(`/desk/queue`, `roleGuard('superadmin','admin')`), `sla-badge`, `desk.service`, `desk.model` (`slaState`).

## Как проверить
- `php artisan test --filter=Desk` — матрица доступа (сотрудник/руководитель/коллега/HR), скрытие внутренних заметок,
  первая реакция и статусы, флаги SLA, идемпотентность `desk.sla`, файлы.
- Вручную: открыть обращение сотрудником; в очереди админом ответить внутренней заметкой — у сотрудника её нет;
  публичным ответом — появился `first_response_at`.


> Роли: «админ (HR)» здесь — это `UserRole::hrStaff()`: `superadmin`, `admin` и `hr_manager` (с 2026-10-09, [auth.md](auth.md)).
