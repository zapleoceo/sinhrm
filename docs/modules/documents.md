# Модуль Documents (документы сотрудников и «Ознайомлений»)

## Что это и зачем
HR готовит документы сотрудникам — приказ о приёме, правила внутреннего распорядка, NDA — и просит подтвердить, что
человек с ними ознакомился. Модуль делает это без бумаги:

- **Шаблоны документов** с переменными: `{ПІБ}`, `{Ім'я}`, `{Посада}`, `{Відділ}`, `{Філія}`, `{Дата прийому}`,
  `{Дата звільнення}`, `{Керівник}`, `{Сьогодні}` (дата по Киеву — `UserTime`, не UTC: в 00:30 по Киеву уже новый день; 2026-10-08,
  MySQL e2e раунд 2, тест `DocumentsApiTest::test_today_variable_is_the_kyiv_date_after_midnight`). Текст — обычный текст или Markdown (заголовки `#`, **жирный**,
  списки). Вставлять HTML нельзя: он показывается как текст.
- **Документ сотрудника** создаётся из шаблона (переменные подставляются один раз — дальнейшие правки шаблона готовые
  документы не меняют) или пишется вручную; к нему можно приложить файл (PDF, PNG, JPG, DOCX до 2 МБ).
- **«Надіслати на ознайомлення»** — у сотрудника появляется задача в «Мої задачі» и документ в «Мої документи».
  Сотрудник читает и нажимает **«Ознайомлений»** (или «Відхилити» с причиной — HR исправит и отправит снова).
  Фиксируется, кто и когда подтвердил.
- **КЕП (Дія.Підпис / Вчасно)** — пока только заготовка в «Інтеграціях» (выключена): квалифицированная подпись требует
  выбора провайдера, договора и юридической проверки. Сейчас «Ознайомлений» — **простое подтверждение в системе, не
  электронная подпись**.

**Кто что видит:** админ (HR) — все документы и черновики, создаёт, правит, отправляет, архивирует; сотрудник — свои
документы, кроме черновиков, и подтверждает только свои; руководитель — документы людей ниже себя (читать, кроме
черновиков); остальные — ничего (404). Документы не удаляются — архивируются.

## Как пользоваться
- Просмотр документа (`document-view.dialog.ts`) открывается через `wideDialog()` (720px, ≤95vw) — раньше `min-width: 40rem` не помещался в стандартные 560px диалога и давал горизонтальную прокрутку.
- **Адміністрування → Шаблони документів** (`/admin/documents/templates`): название, категория (например «Накази»),
  текст; кнопки-переменные вставляют `{…}` в позицию курсора; справа — предпросмотр с примером (вымышленные данные).
  Неизвестная переменная (опечатка `{Імя}`) — ошибка при сохранении.
- **Профиль сотрудника → вкладка «Документи»**: список; админ — «Створити з шаблону», «Новий документ», «Завантажити
  файл», «Надіслати на ознайомлення», «В архів». Открыть документ — текст, файл (скачивание) и отметки об ознакомлении.
- **«Мої документи»** (`/me/documents`): свои документы, кнопки «Ознайомлений» / «Відхилити».
- Воркфлоу может создать документ сам (действие `create_document`, [workflows.md](workflows.md)).

## Как устроено

**Ошибки бизнес-правил** (DRY, 2026-10-08): `Exceptions/DocumentException` наследует `Core\Exceptions\BusinessRuleException` — общий конструктор (код, HTTP-статус, `extra`) и `render()` в JSON `{message, code, ...extra}`; модуль объявляет только именованные коды, ответ API прежний.

- Фронт (2026-10-08): списки «Мої документи», вкладки документов сотрудника и шаблонов держит `PagedList` (`core/ui/table/paged-list.ts`) вместо своих `load()`; смена сотрудника отменяет запрос в пути.
- Счётчик в меню ([shell.md](shell.md), `GET /api/nav/badges`, [core.md](core.md)): `Services/DocumentNavBadges` — ключ `my_documents`: мои документы, которые можно подписать/ознакомиться (статус `sent`, в списке у них `can_acknowledge = true`); `DocumentService::countAwaitingMe()` — `count(*)` с тем же фильтром, что `mine()`.
Бэкенд — `backend/app/Modules/Documents`, маршруты под `/api` (`routes.php`), все за `auth:sanctum` +
`EnsureUserIsActive`. Gate `documents-manage` (`Providers/DocumentsServiceProvider::MANAGE`) = `PeopleScope::isAdmin`.

### Таблицы (миграция `Database/Migrations/2026_10_03_200001_create_documents_tables.php`)
| Таблица | Колонки | Заметки |
|---|---|---|
| `document_templates` | `name, category?, body text, archived, created_by?` | удаления нет — архив |
| `documents` | `employee_id, template_id?, title, category?, status (draft\|sent\|signed\|rejected\|archived), content_md?, file_path?, reject_reason?, created_by?, sent_at?` | `content_md` хранится уже заполненным; `file_path` — ссылка хранилища (`db:<id>`) |
| `documents_files` | `document_id (unique), filename, mime, size, sha256, content (base64)` | временное хранилище маленьких файлов; `content` скрыт от сериализации |
| `signatures` | `document_id, signer_employee_id?, signer_user_id?, method (manual_ack\|kep_pending), signed_at, ip_hash?, user_agent_hash?` | `unique(document_id, signer_user_id)`; IP и браузер — только HMAC-SHA256 с `APP_KEY` (сырые значения не хранятся, в API хэшей нет) |

Переходы: `draft → sent → signed | rejected`; `rejected` можно исправить и отправить снова (задача та же, открывается
заново); из любого статуса — `archived`. Править название, категорию, текст и файл можно только в `draft`/`rejected` (иначе 409 `not_editable`): после отправки сотруднику
и подписи документ заморожен целиком, разрешено только отправить в архив (`status: archived`).

### Безопасный показ (`Support/MarkdownRenderer`, `Support/TemplateFiller`)
Markdown превращается в HTML **на сервере** (league/commonmark): `html_input = escape` — любой HTML (`<script>`,
`<img onerror>`) показывается как текст; `allow_unsafe_links = false` — ссылки `javascript:`, `data:` и т.п.
выбрасываются. Поэтому и шаблон, и имя сотрудника, подставленное в него, не могут внедрить разметку. Фронтенд выводит
уже очищенный `html`. Переменные — `TemplateFiller::fill` (нет значения → «—» и список `missing`), `unknown()` —
неизвестные `{токены}` (длиннее 40 символов или через перевод строки — не токен, а текст). Значения —
`Services/DocumentVariables` (`{Ім'я}` — первое слово ФИО, даты `dd.mm.yyyy`).

### Файлы (`Contracts/DocumentStorage` → `Repositories/DatabaseDocumentStorage`)
Интерфейс `put / get / delete`; сейчас файлы до 2 МБ лежат в `documents_files` (base64). Проверки **в самом
хранилище** (не только в FormRequest): размер ≤ `MAX_BYTES`, тип определяется по содержимому (`finfo`) и должен быть из
белого списка `application/pdf`, `image/png`, `image/jpeg`, DOCX (ZIP-контейнер с именем `.docx`). Переименованный
HTML с расширением `.pdf` отклоняется (422 `invalid_file`). Скачивание — всегда `Content-Disposition: attachment`,
`X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store` (файл не открывается в браузере на нашем домене).
Переход на объектное хранилище (например, Vercel Blob) — вторая реализация интерфейса и одна строка в провайдере.

### Эндпоинты
| Метод и путь | Кто | Тело / параметры → ответ |
|---|---|---|
| `GET /api/documents/templates` | admin | `?archived=1` — с архивными |
| `POST /api/documents/templates` | admin | `{name, body, category?}` → 201; неизвестная переменная → 422 `unknown_variables` + `variables[]` |
| `PATCH /api/documents/templates/{id}` | admin | `{name?, body?, category?, archived?}` |
| `POST /api/documents/templates/preview` | admin | `{body, employee_id?}` → `{markdown, html, missing[], unknown[]}` (без сотрудника — вымышленный пример) |
| `GET /api/documents` | активный | `employee_id?, status?, category?`; admin — все, остальные — свои и людей ниже себя, без черновиков; до 200 |
| `GET /api/me/documents` | активный | свои без черновиков; нет связанного сотрудника → пусто |
| `GET /api/documents/{id}` | кто видит | + `html` (очищенный), `content_md` — только админу |
| `GET /api/documents/{id}/file` | кто видит | скачивание; нет файла → 404 |
| `POST /api/documents` | admin | `{employee_id, template_id?, title? (обязателен без шаблона), category?, content_md?}` → 201 черновик; архивный шаблон → 422 `template_archived` |
| `PATCH /api/documents/{id}` | admin | `{title?, category?, content_md?, status?: "archived"}` |
| `POST /api/documents/{id}/file` | admin | multipart `file` (pdf/png/jpg/docx, ≤ 2 МБ) → документ; 422 `invalid_file` / `file_too_large` / валидация |
| `POST /api/documents/{id}/send` | admin | → `sent` + задача сотруднику (тип `document`, ключ `doc:<id>`, ссылка `/me/documents`, срок 3 дня); пусто → 422 `empty_document`; у сотрудника нет логина → 422 `employee_has_no_login` |
| `POST /api/documents/{id}/acknowledge` | сам сотрудник | → `signed` + подпись `manual_ack`, задача закрыта; повтор → 409 `already_signed`; не отправлен → 409 `not_sent`; чужой → 404 |
| `POST /api/documents/{id}/reject` | сам сотрудник | `{reason?}` → `rejected`, задача закрыта |

### Слои
`Http/Controllers` (`DocumentTemplateController`, `DocumentController`) → `Http/Requests` → `Services`
(`DocumentTemplateService`, `DocumentService`, `DocumentVariables`) → `Contracts/DocumentRepository`,
`DocumentTemplateRepository`, `DocumentStorage` (`Repositories/*`). Ошибки — `Exceptions/DocumentException`. Связи:
People (`PeopleScope`, `EmployeeService`), Scripts (задача «ознайомитися» через контракт `TaskScheduler`), Workflows вызывает
`DocumentService::generate/send`.

### Фронтенд (`frontend/src/app/features/documents`)
| Файл | Что |
|---|---|
| `documents.model.ts`, `documents.service.ts` | типы, HTTP (включая multipart-загрузку и скачивание), `documentsErrorKey`, вставка переменной в позицию курсора |
| `templates/document-templates.page.ts` | `/admin/documents/templates`: список, редактор, чипы переменных, предпросмотр (запрос с задержкой) |
| `profile/employee-documents.tab.ts`, `profile/document-create.dialog.ts` | вкладка «Документи» профиля: создать из шаблона / вручную, файл, отправить, архив |
| `document-view.dialog.ts` | просмотр: `html` с сервера через `[innerHTML]` (Angular-санитайзер включён, `bypassSecurityTrust*` не используется — двойная защита поверх серверной очистки), файл, отметки |
| `my/my-documents.page.ts` | `/me/documents`: «Ознайомлений», «Відхилити» |

Строки — `documents.*`; спеки — `documents.spec.ts`. «Мої задачі» — `features/tasks` ([tasks.md](tasks.md)).

### Персональные данные
`Privacy\DocumentsPersonalData`: в выгрузку сотрудника идёт список документов (название, статус, даты, имя/тип/размер
файла — без самого файла); при удалении данных бывшего сотрудника у неподписанных документов (чернетка, отправлен,
отклонён) стираются текст и файл, подписанные и архивные хранятся как кадровые. Подробно — [privacy.md](privacy.md).

### Шаблоны офферов

Категория `offer` — шаблоны офферов для Recruiting (`docs/modules/recruiting.md`). Добавлены переменные `{Зарплата}`,
`{Дата виходу}`, `{Умови}`: их заполняет только оффер; в документах сотрудника они остаются «—».
Recruiting берёт шаблоны через контракт `Contracts\DocumentTemplateRepository` (`find()` и `activeOfCategory()` —
неархивные шаблоны категории по имени, только id и имя), а не через модель `DocumentTemplate`. Тест —
`DocumentsApiTest::test_active_of_category_gives_live_templates_of_one_category_by_name`.

**Вид (рестайл C «Маршрут», 2026-10-02).** Статус документа — пилюля `.app-pill` (`DOCUMENT_STATUS_TONE`: черновик/архив — пунктирный ○, отправлен ◆ warn, подписан ● good, отклонён ■ bad); архивный документ — приглушённое название без потери контраста (не opacity); кнопка-название в профиле — 44px на телефоне; пустой список — `.app-empty` (пунктирная ветка). Тест вида — `features/documents/documents.restyle.spec.ts` (контракт стилей: только токены темы, без hex, линии 1.5px, без «бледности» через opacity).

### Общие хелперы Core (2026-10-02)
- скачивание файла документа — `Core\Http\Responses\Download::file()`: те же заголовки, что раньше (attachment с ASCII-именем и `filename*`, `nosniff`, `private, no-store`, `Content-Length`); тот же хелпер у вложений Desk и у CV отклика со страницы вакансий (`GET /api/applications/{id}/cv`, [recruiting.md](recruiting.md));
- gate `documents-manage` задаётся `ModuleServiceProvider::defineRoleGate(…, UserRole::hrStaff())`: активный superadmin, admin или hr_manager — тот же набор, что `PeopleScope::isAdmin` (модуль больше не импортирует `PeopleScope` ради gate);
- Загрузка файла `POST /api/documents/{id}/file` — именованный лимитер `documents-upload` (`DocumentsServiceProvider::UPLOAD_THROTTLE`), 30 в минуту на пользователя, своя корзина; 31-я — 429 (`DocumentsApiTest::test_file_upload_is_throttled_per_user`). Даты предпросмотра шаблона (`DocumentVariables::sample`) — по Киеву, как `{Сьогодні}`. Комментарии маршрутов: gate — HR staff (`UserRole::hrStaff()`: superadmin, admin, hr_manager).
- текущий пользователь в контроллерах — общий трейт `Core\Http\Concerns\ResolvesActor` вместо приватной копии `actor()`.

Поведение API не менялось; подробности — [core.md](core.md), раздел «Общие хелперы модулей».

### Общие примитивы фронта
Общий код фронта лежит в `frontend/src/app/core` ([core.md](core.md)); фича его только вызывает.
- Ошибки API → i18n-ключ: `documentsErrorKey` — обёртка над общим `apiErrorKey` (`core/api/api-error.ts`) со своими кодами, списком статусов и запасным ключом; набор ключей и тексты прежние.
- Короткие уведомления (toast) — `NotifyService.show(key, { params?, duration? })` из `core/ui/notify.service.ts` вместо своего `toast()` с `MatSnackBar`; тексты, длительности и доступность (вежливая live-область snack bar) прежние.
- HTTP-сервис фичи снимает обёртку ответа `{ data }` общим оператором `unwrapData()` (`core/api/unwrap-data.ts`, тип `DataEnvelope<T>` из `core/api/api.model.ts`) вместо своего `map((r) => r.data)`; параметры запроса без пустых значений — `toParams` из `core/api/http-params.ts`, страница списка — `Paged<T>` оттуда же. Контракт API не менялся.

### Зависимости через контракты (2026-10-08)
- `DocumentController`, `DocumentTemplateController` и `DocumentNavBadges` берут контекст и карточку сотрудника через контракты People `PeopleAccess` и `EmployeeLookup`. Тест — `tests/Unit/Documents/DocumentsPeopleContractsTest.php` (предпросмотр шаблона с сотрудником из контракта).
- `DocumentService` ставит, закрывает и отмечает задачи «ознакомиться» через контракт Scripts `TaskScheduler` (`schedule`, `closeByRule`, `setDone`).

## Как проверить
Бэкенд: `tests/Feature/Documents/DocumentsApiTest` (401/403, неизвестные переменные и архив шаблонов, предпросмотр:
`<script>` экранируется, `javascript:` и `<img>` не проходят, переменные сотрудника и «—», генерация из шаблона
заморожена, матрица доступа admin / сам / руководитель прямой и через уровень / коллега / посторонний, черновики
скрыты, поток «надіслати → ознайомлений» с задачей и хэшами, отклонение и повторная отправка, нет логина → 422, файлы:
тип по содержимому, размер, скачивание вложением). Unit: `tests/Unit/Documents/DocumentsSupportTest` (подстановка,
неизвестные токены, значения сотрудника, очистка Markdown, определение типа файла и лимит хранилища). Фронт: спеки в
`features/documents`.

**Не проверено в этой задаче:** запросы к preview/prod; тесты на SQLite (в CI — MySQL 8.4); DOCX с реального Word
(в тестах — синтетический ZIP-заголовок; определение типа зависит от версии libmagic на сервере).

## Вопросы и следующие шаги
- КЕП: какой провайдер (Дія.Підпис или Вчасно), договор/доступ к API, нужен ли вход для подписи.
- Объектное хранилище для файлов больше 2 МБ (Vercel Blob?) — решение владельца.
- Папки/права по категориям (как в PeopleForce) — сейчас простая строка `category` и фильтр.

## Доступ к модулю

Ключ модуля `documents`. Суперадмин может выключить модуль для всей компании или скрыть его от части ролей на странице «Адміністрування → Модулі». По умолчанию: включён, роли — все роли (как и до появления выключателя). Выключенный модуль отвечает 403 `module_disabled`, его фоновые задачи пропускаются, данные не удаляются. Подробнее — [modules-access.md](modules-access.md).

### Проверка MySQL перед переносом

Содержимое `documents_files.content` — base64 в `longText`; исходный файл до 2 МиБ занимает в БД больше 2 МиБ. Feature-тест на MySQL 8.4 записывает файл ровно 2 МиБ, читает его обратно и сверяет байты и SHA-256. При переносе существующих файлов требуется такая же побайтовая сверка каждого вложения.
