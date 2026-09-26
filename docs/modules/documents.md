# Модуль Documents (документы сотрудников и «Ознайомлений»)

## Что это и зачем
HR готовит документы сотрудникам — приказ о приёме, правила внутреннего распорядка, NDA — и просит подтвердить, что
человек с ними ознакомился. Модуль делает это без бумаги:

- **Шаблоны документов** с переменными: `{ПІБ}`, `{Ім'я}`, `{Посада}`, `{Відділ}`, `{Філія}`, `{Дата прийому}`,
  `{Дата звільнення}`, `{Керівник}`, `{Сьогодні}`. Текст — обычный текст или Markdown (заголовки `#`, **жирный**,
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
- **Адміністрування → Шаблони документів** (`/admin/documents/templates`): название, категория (например «Накази»),
  текст; кнопки-переменные вставляют `{…}` в позицию курсора; справа — предпросмотр с примером (вымышленные данные).
  Неизвестная переменная (опечатка `{Імя}`) — ошибка при сохранении.
- **Профиль сотрудника → вкладка «Документи»**: список; админ — «Створити з шаблону», «Новий документ», «Завантажити
  файл», «Надіслати на ознайомлення», «В архів». Открыть документ — текст, файл (скачивание) и отметки об ознакомлении.
- **«Мої документи»** (`/me/documents`): свои документы, кнопки «Ознайомлений» / «Відхилити».
- Воркфлоу может создать документ сам (действие `create_document`, [workflows.md](workflows.md)).

## Как устроено
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
заново); из любого статуса — `archived`. Править текст и файл можно только в `draft`/`rejected` (иначе 409 `not_editable`).

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
People (`PeopleScope`, `EmployeeService`), Scripts (задача «ознайомитися» через `TaskService`), Workflows вызывает
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

## Как проверить
Бэкенд: `tests/Feature/Documents/DocumentsApiTest` (401/403, неизвестные переменные и архив шаблонов, предпросмотр:
`<script>` экранируется, `javascript:` и `<img>` не проходят, переменные сотрудника и «—», генерация из шаблона
заморожена, матрица доступа admin / сам / руководитель прямой и через уровень / коллега / посторонний, черновики
скрыты, поток «надіслати → ознайомлений» с задачей и хэшами, отклонение и повторная отправка, нет логина → 422, файлы:
тип по содержимому, размер, скачивание вложением). Unit: `tests/Unit/Documents/DocumentsSupportTest` (подстановка,
неизвестные токены, значения сотрудника, очистка Markdown, определение типа файла и лимит хранилища). Фронт: спеки в
`features/documents`.

**Не проверено в этой задаче:** запросы к preview/prod; тесты на SQLite (в CI — Postgres); DOCX с реального Word
(в тестах — синтетический ZIP-заголовок; определение типа зависит от версии libmagic на сервере).

## Вопросы и следующие шаги
- КЕП: какой провайдер (Дія.Підпис или Вчасно), договор/доступ к API, нужен ли вход для подписи.
- Объектное хранилище для файлов больше 2 МБ (Vercel Blob?) — решение владельца.
- Папки/права по категориям (как в PeopleForce) — сейчас простая строка `category` и фильтр.

## Доступ к модулю

Ключ модуля `documents`. Суперадмин может выключить модуль для всей компании или скрыть его от части ролей на странице «Адміністрування → Модулі». По умолчанию: включён, роли — все роли (как и до появления выключателя). Выключенный модуль отвечает 403 `module_disabled`, его фоновые задачи пропускаются, данные не удаляются. Подробнее — [modules-access.md](modules-access.md).
