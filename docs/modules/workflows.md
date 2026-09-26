# Модуль Workflows (онбординг, офбординг, сценарии)

## Что это и зачем
Воркфлоу — это **список шагов, который запускается для конкретного сотрудника**: «за 2 дня до выхода подготовить
рабочее место», «в первый день отправить правила на ознакомление», «через неделю назначить наставника», «в последний
день забрать технику». HR один раз описывает шаблон, а дальше система сама создаёт задачи нужным людям в нужные дни.

- **Онбординг** запускается сам, когда в системе появляется новый сотрудник (вручную или кнопкой «Створити
  співробітника» из рекрутинга). **Офбординг** — когда сотрудника увольняют. Есть запуск по **окончанию
  испытательного срока** и **ручной** запуск из профиля.
- У каждого шага — **день относительно даты-якоря** (дата приёма, дата увольнения или дата запуска; `-2` = за два дня
  до, `0` = в этот день, `7` = через неделю) и **исполнитель**: сам сотрудник, его руководитель, HR (админ) или
  конкретный пользователь.
- Шаги-действия: создать задачу, попросить заполнить форму, отправить письмо, создать событие в календаре, создать
  документ из шаблона (и отправить на ознакомление), попросить загрузить документ, вебхук во внешнюю систему,
  запустить другой воркфлоу, уведомить руководителя, назначить наставника.
- Задачи из воркфлоу попадают в общий список **«Мої задачі»** (вместе с задачами рекрутинга и документов). Отметил
  задачу — шаг выполнен. Когда выполнены все шаги — воркфлоу завершён.

**Кто что видит.** Шаблоны, запуск, отмена и повтор упавшего шага — только админы (они же HR). Руководитель видит
воркфлоу своих людей (всех ниже себя по оргструктуре). Сотрудник видит **свои задачи**, а не весь воркфлоу (офбординг
может содержать то, что ему видеть не нужно). Отметить шаг может его исполнитель или админ.

**Изменение шаблона не ломает уже идущие воркфлоу**: при запуске шаги копируются («снимок»), и запущенный воркфлоу
доживает по старому плану.

## Как пользоваться
- **Адміністрування → Воркфлоу** (`/admin/workflows`): список шаблонов, «Створити». В редакторе: название, тип
  (онбординг / офбординг / довільний), запуск (вручну / при прийомі / при звільненні / по закінченні
  випробувального терміну + сколько дней испытательный срок), активность; шаги перетаскиваются за ручку, у каждого —
  название, действие, день, исполнитель и настройки действия. Для вебхука — отдельный блок «Ключ підпису».
- **Люди → Воркфлоу** (`/workflows/runs`): все запущенные воркфлоу (админ — все, руководитель — своих людей),
  фильтры по статусу, шаблону, сотруднику; внутри — шаги с кнопками «Виконано», «Пропустити», «Повторити» (упавший шаг).
- **Профиль сотрудника → вкладка «Воркфлоу»** (админам и руководителям): воркфлоу этого человека; админ —
  «Запустити воркфлоу» (шаблон + дата-якорь, по умолчанию сегодня).
- **«Мої задачі»** (`/tasks`): задачи из воркфлоу с быстрым «выполнено».

Шаги выполняются фоновым заданием раз в ~30 минут (cron), поэтому задача на «сегодня» появляется в течение получаса
после запуска или наступления дня.

## Как устроено
Бэкенд — `backend/app/Modules/Workflows`, маршруты `/api/workflows/*` (`routes.php`), все за `auth:sanctum` +
`EnsureUserIsActive`. Gate `workflows-manage` (`Providers/WorkflowsServiceProvider::MANAGE`) = `PeopleScope::isAdmin`.

### Таблицы (миграция `Database/Migrations/2026_10_03_300001_create_workflows_tables.php`)
| Таблица | Колонки | Заметки |
|---|---|---|
| `workflow_templates` | `name, kind (onboarding\|offboarding\|custom), trigger (manual\|employee_hired\|employee_terminated\|probation_end), active, probation_days (по умолч. 90), created_by?` | удаление — только без запусков (иначе 409 `has_runs`, деактивируйте) |
| `workflow_steps` | `template_id, position, title, action, offset_days (-365..365), assignee_rule (employee\|manager\|hr_admin\|specific_user), assignee_user_id?, config jsonb` | `config` проверяет исполнитель действия; секретов в нём нет |
| `workflow_runs` | `template_id, employee_id, template_name, anchor_date, status (running\|completed\|cancelled), started_by?, trigger_key?, parent_run_id?, depth, completed_at?` | `trigger_key = "<триггер>:<дата-якорь>"` (например `employee_hired:2026-10-05`), `unique(template_id, employee_id, trigger_key)` — автозапуск идемпотентен **на один случай** (повторное событие того же найма игнорируется), а повторный найм с новой `hired_at` / новое увольнение запускает шаблон снова; ручной запуск (`trigger_key = null`) — всегда новый |
| `workflow_run_steps` | `run_id, step_id? (null on delete), position, snapshot jsonb, assignee_id?, due_at, status (pending\|done\|skipped\|failed), executed_at?, attempts, completed_by?, completed_at?, result jsonb?` | `snapshot` — копия шага при запуске; `result` — только коды и id |

### Запуск (`Services/WorkflowStarter`)
Снимок шагов шаблона → `workflow_run_steps`, `due_at = anchor_date 00:00 + offset_days`, исполнитель вычисляется
сразу (`Services/AssigneeResolver`): `employee` → логин сотрудника, `manager` → логин руководителя, `hr_admin` → админ,
запустивший воркфлоу, иначе первый активный superadmin/admin, `specific_user` → указанный пользователь (заблокированный
= никто). Шаблон без шагов сразу `completed`. Ничего не выполняется при запуске.

**Триггеры** (`Services/WorkflowTriggers`, слушатели в `Listeners/`): событие People `EmployeeHired` (ручное создание и
найм из рекрутинга) → активные шаблоны `employee_hired`, якорь `hired_at`; `EmployeeTerminated` → `employee_terminated`,
якорь `fired_at`; `probation_end` — в тике: неуволенные сотрудники, у которых `hired_at + probation_days` попадает в
последние 7 дней (`PROBATION_WINDOW_DAYS`, переживает пропуски cron), якорь — эта дата. Повторное событие того же случая (тот же триггер и та же дата-якорь) не создаёт
второй запуск; повторный найм с новой датой приёма — создаёт (уникальный индекс + `insertOrIgnore`, безопасно при гонке). Ошибка одного шаблона пишется в лог
`workflows.trigger_failed` и **не ломает** запрос найма/увольнения.

### Выполнение (`Services/StepRunner`, задание `workflows.tick`)
`Services/WorkflowTickJob` зарегистрирован как `Core\Contracts\ScheduledJob` → `POST /api/ops/jobs/run` (cron ~30 мин):
запускает `probation_end`, затем выполняет до 50 (`StepRunner::BATCH`) созревших шагов (`pending`, `executed_at` пуст,
`due_at <= now`, воркфлоу `running`). Шаг сначала **захватывается** атомарным `UPDATE … WHERE executed_at IS NULL` —
два пересекающихся cron-вызова не выполнят его дважды. Ответ задания: `{probation_started, executed, done, waiting,
skipped, failed}`.

Исполнители — `Contracts/StepExecutor` (`action()`, `configRules()`, `execute(StepContext): StepOutcome`),
регистрируются тегом `workflows.executors` (Open/Closed: новое действие = класс + строка в провайдере + значение enum;
исполнитель может жить в другом модуле и тегироваться из его провайдера — так подключён `collect_assets` из Assets).
`Support/ExecutorRegistry` находит исполнителя по действию. Результат: `done`, `skipped` (код причины), `failed` (код
ошибки) или `waiting` (создана задача — шаг ждёт человека).

| Действие | Что делает | Настройки (`config`) |
|---|---|---|
| `create_task` | задача исполнителю, ссылка — профиль сотрудника; шаг ждёт задачу | `title?` |
| `request_form` | задача «заповнити форму», ссылка — https-адрес формы или профиль | `title?`, `url?` (https) |
| `upload_document_request` | задача «надати документ …», ссылка — вкладка «Документи» профиля | `document_name` |
| `assign_buddy` | задача выбрать наставника (поля «наставник» пока нет) | `title?` |
| `collect_assets` | офбординг: задача со списком активов, которые числятся за сотрудником («Зібрати активи: INV-001 Ноутбук, …», ссылка — вкладка «Активи»); шаг ждёт задачу; ничего не числится → `skipped: no_assets`. Исполнитель живёт в модуле Assets и подключается тегом `workflows.executors` из `AssetsServiceProvider` ([assets.md](assets.md)) | `title?` (≤ 120) |
| `notify_manager` | задача-уведомление руководителю сотрудника, **не блокирует** (шаг сразу `done`); нет руководителя с логином → `skipped: no_manager` | `message?` |
| `create_document` | документ из шаблона модуля Documents; `send` — сразу на ознакомление; у сотрудника нет логина → черновик, `sent: false, reason: employee_has_no_login` | `document_template_id`, `send?` |
| `webhook` | POST JSON на https-адрес (см. ниже) | `url` (https) |
| `start_workflow` | вложенный воркфлоу того же сотрудника (якорь — день выполнения), глубина ≤ 3 (`WorkflowStarter::MAX_DEPTH`), глубже → `failed: depth_limit` (это же останавливает петли A → B → A); неактивный шаблон → `skipped: template_inactive` | `template_id` |
| `send_email_template` | письмо сотруднику (рабочий e-mail, иначе личный) через подключённый Gmail (`GoogleWorkspace\Contracts\Mailer`); в теме и тексте `{{name}}` → ФИО; текст уходит как простой текст (HTML экранируется). Gmail не подключён → `skipped: not_connected`; подключён только на чтение → `skipped: reconnect_to_send`; нет e-mail → `skipped: no_recipient`; лимит 60 писем/час → `failed: rate_limited` («Повторити» позже); ошибка Google → `failed: <код>`; успех → `done {message_id}` | `subject`, `body` |
| `add_calendar_event` | событие в подключённом Google Calendar в день шага в `time` (по умолч. 10:00), участники — рабочий e-mail сотрудника и исполнитель, Google приглашений не шлёт; не подключён → `skipped: not_connected` | `title?`, `time?` (HH:MM), `duration_minutes?` (15..480), `online?` |

Задачи создаются в общей таблице `tasks` модуля Scripts (`TaskService::schedule`, тип `workflow`, `employee_id`,
`link`, ключ `wf:<id шага запуска>` — один раз). Нет исполнителя (у сотрудника/руководителя нет логина) → задача HR,
в `result` — `assignee_fallback: true`; нет и HR → `failed: no_assignee`. Отметка задачи в «Мої задачі» →
событие Scripts `TaskCompleted` → `Listeners/CompleteStepFromTask` закрывает шаг. И наоборот: шаг закрыт через API или
отменён — его задача закрывается.

**Ошибки и повтор.** Исключение исполнителя = `failed` с кодом `exception:<Класс>` (текст исключения не сохраняется —
в нём могут быть адреса и ключи). Упавший шаг cron не повторяет; админ нажимает «Повторити» — шаг выполняется **сразу**
в этом запросе (`attempts` растёт). Воркфлоу с упавшим шагом остаётся `running`, пока шаг не повторят или не пропустят.

### Вебхук (`Executors/WebhookExecutor`)
1. `OutboundUrlGuard` (модуль Integrations): только https, порт 443, все IP хоста публичные (без localhost, частных
   сетей, `169.254.169.254` и т.п.) — иначе `failed` с кодом `invalid_url` / `blocked_port` / `blocked_host` /
   `unresolved_host`, **запрос не отправляется**. Редиректы не выполняются, таймаут 10 с.
2. Ключ подписи — **свой у каждого шаблона**, хранится в `SecretVault` (`integration_secrets`, ключ интеграции
   `workflows`, имя `webhook_secret:<id шаблона>`), задаётся `PUT …/webhook-secret`; API показывает только `is_set` и
   маску. Нет ключа → `failed: missing_secret`.
3. Тело: `{event: "workflow.step", run_id, run_step_id, template{id,name}, step{title,action}, anchor_date,
   employee{id, full_name, work_email, hired_at, fired_at, branch_id, department_id, position_id}, sent_at}` — без
   личного уровня данных. Заголовки: `X-SinHRM-Event: workflow.step`, `X-SinHRM-Signature: sha256=<HMAC-SHA256(тело, ключ)>`.
   Получатель считает HMAC от **сырого** тела и сравнивает с заголовком (постоянным по времени сравнением).
4. Ответ 2xx → `done {http_status}`, иначе `failed: http_<код>`, сеть — `failed: connection_error`. В лог и `result`
   не попадают ни адрес, ни тело, ни ключ.

### Эндпоинты
| Метод и путь | Кто | Тело / параметры → ответ |
|---|---|---|
| `GET /api/workflows/templates` | admin | шаблоны с шагами, `runs_count`, `webhook_secret{is_set, masked, updated_at}` |
| `GET /api/workflows/templates/{id}` | admin | шаблон |
| `POST /api/workflows/templates` | admin | `{name, kind, trigger, active?, probation_days? (1..730), steps: [{title, action, offset_days, assignee_rule, assignee_user_id? (обязателен для specific_user), config}]}` → 201; ошибки конфигурации — `steps.N.config.<ключ>` (422); неизвестные ключи `config` отбрасываются |
| `PUT /api/workflows/templates/{id}` | admin | то же целиком; шаги с `id` обновляются, новые создаются, отсутствующие удаляются; идущие воркфлоу не меняются |
| `POST /api/workflows/templates/{id}/steps/reorder` | admin | `{ids: [все id шагов в новом порядке]}`; не тот набор → 422 `invalid_order` |
| `DELETE /api/workflows/templates/{id}` | admin | 204; есть запуски → 409 `has_runs` |
| `PUT /api/workflows/templates/{id}/webhook-secret` | admin | `{secret: string 16..200 \| null}` → шаблон (значение не возвращается) |
| `GET /api/workflows/runs` | активный | `employee_id?, template_id?, status?` (строкой — ок); admin — все, руководитель — сотрудники ниже себя, остальные — пусто; до 200, новые сверху |
| `GET /api/workflows/runs/{id}` | admin, руководитель выше | воркфлоу со снимками шагов, `progress{finished,total}`, `has_failed`, `can_cancel`, у шага `waiting, can_complete, can_retry, result`; иначе 404 |
| `POST /api/workflows/runs` | admin | `{template_id, employee_id, anchor_date? (Y-m-d, по умолч. сегодня)}` → 201 |
| `POST /api/workflows/runs/{id}/cancel` | admin | открытые шаги → `skipped (cancelled)`, их задачи закрываются; не `running` → 409 `run_not_running` |
| `POST /api/workflows/runs/{run}/steps/{step}/complete` | исполнитель шага, admin | → `{id, run_id, status, completed_at, result, run_status}`; чужой → 403; уже закрыт → 409 `step_not_open`; можно закрыть и ещё не наступивший шаг |
| `POST /api/workflows/runs/{run}/steps/{step}/skip` | исполнитель шага, admin | `{reason?}` → как выше |
| `POST /api/workflows/runs/{run}/steps/{step}/retry` | admin | только `failed` (иначе 409 `step_not_failed`); выполняется сразу |

### Слои
`Http/Controllers` (`WorkflowTemplateController`, `WorkflowRunController`) → `Http/Requests` (`SaveWorkflowTemplateRequest`
проверяет `config` правилами исполнителя) → `Services` (`WorkflowTemplateService`, `WorkflowRunService`,
`WorkflowStarter`, `StepRunner`, `WorkflowTriggers`, `AssigneeResolver`, `WorkflowTickJob`) → `Contracts/*Repository`
(`Repositories/Eloquent*`, `EloquentAssigneeDirectory`). `Executors/*` — действия; `Support/WebhookSecrets` — ключ в
хранилище. Ошибки — `Exceptions/WorkflowException` (`{message, code}`). Связи: People — события, `PeopleScope`,
`EmployeeRepository`; Scripts — задачи и `TaskCompleted`; Documents — `create_document`; Integrations —
`OutboundUrlGuard`, `SecretVault`; GoogleWorkspace — состояние подключения и `CalendarClient`; Core — `ScheduledJob`.

### Фронтенд (`frontend/src/app/features/workflows`)
| Файл | Что |
|---|---|
| `workflows.model.ts`, `workflows.service.ts` | типы API, HTTP-клиент, `workflowsErrorKey`, ключи кодов результата шага, прогресс |
| `templates/workflow-templates.page.ts` | `/admin/workflows`: список, создание, удаление (409 `has_runs` → подсказка деактивировать) |
| `editor/workflow-editor.page.*`, `editor/workflow-editor.store.ts`, `editor/step-config.ts` | `/admin/workflows/:id`: поля шаблона, шаги с CDK drag&drop (сохранённые шаги без других правок — сразу `reorder`), форма настроек по действию, ключ подписи вебхука |
| `runs/workflow-runs.page.ts`, `runs/runs.store.ts`, `runs/run-card.ts` | `/workflows/runs`: доска с фильтрами; карточка запуска — шаги, «Виконано / Пропустити / Повторити», отмена |
| `runs/employee-runs.tab.ts` | вкладка «Воркфлоу» профиля (админ и руководители), «Запустити воркфлоу» |
| `confirm.dialog.ts` | Material-подтверждение (с необязательной причиной) вместо `confirm()/prompt()` |

Строки — `workflows.*` в `public/i18n/{uk,ru,en}.json`; спеки — `workflows.spec.ts`.

**Интерфейс (2026-09-26):** Даты вводятся только выпадающим календарём Angular Material (формат дд.мм.рррр, неделя с понедельника; [core.md](core.md)), в API уходит прежний `YYYY-MM-DD` (`core/date/iso-date.ts`, без сдвига часового пояса): «Дата відліку» при ручном запуске воркфлоу у сотрудника.

## Как проверить
Бэкенд: `tests/Feature/Workflows/WorkflowTemplatesApiTest` (401/403, CRUD с шагами, проверка `config` каждого действия,
отбрасывание неизвестных ключей, reorder, ключ вебхука не возвращается и хранится зашифрованным, удаление только без
запусков), `WorkflowRunsTest` (найм → один запуск даже при повторном событии, увольнение → офбординг от `fired_at`,
снимок не меняется после правки шаблона, выполнение через `POST /api/ops/jobs/run` один раз и в свой день, задача
сотруднику-viewer и закрытие шага через «Мої задачі», задача HR при отсутствии логина, подпись вебхука
`Http::fake`, отказ guard'а без запроса и повтор, `missing_secret` / `http_500` / повтор, глубина вложенности 3,
Google не подключён → `skipped`, уведомление руководителю, `create_document` с отправкой, `probation_end`, матрица
доступа, завершение/пропуск только исполнителем или админом, отмена). Unit: `tests/Unit/Workflows/ExecutorsTest`
(у каждого действия ровно один исполнитель, снимок, HMAC, событие календаря, Gmail только чтение, коды guard'а,
правила исполнителя). Фронт: спеки в `features/workflows`.

Вручную на preview (сессия admin): создать шаблон «при прийомі» с шагом «створити задачу» (день 0, виконавець HR) →
создать сотрудника → `curl -s -X POST https://<api>/api/ops/jobs/run -H "X-Ops-Secret: $OPS_SECRET"` →
`jobs["workflows.tick"].waiting = 1` → задача в «Мої задачі».

**Не проверено в этой задаче:** запросы к preview/prod (вход только через Google на prod-домене); тесты гонялись на
SQLite (в CI — Postgres); реальные вебхук-получатели и Google Calendar (только `Http::fake` / подменённый клиент).

## Вопросы и следующие шаги
- Письмо (`send_email_template`) работает, когда Google подключён с правом отправки (`gmail.send`); старое подключение
  «только чтение» нужно один раз «Перепідключити».
- Запрос формы ведёт на внешнюю форму (Google Forms) или профиль — своих форм пока нет.
- Нет поля «наставник» у сотрудника: `assign_buddy` — задача, выбор фиксируется её выполнением.
- Метрики шаблона (за 30 дней: запуски, % завершения, среднее время), как в PeopleForce, — не сделаны.


> Роли: «админ (HR)» здесь — это `UserRole::hrStaff()`: `superadmin`, `admin` и `hr_manager` (с 2026-10-09, [auth.md](auth.md)).
