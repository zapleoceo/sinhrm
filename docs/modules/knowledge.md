# Модуль Knowledge (база знаний)

## Что это и зачем
Ответы на частые вопросы, правила офиса, инструкции — в одном месте, чтобы не спрашивать HR одно и то же. Статьи
разложены по категориям, есть поиск, теги, отметка «Корисно?» и история правок. Статью можно показать всем, только
некоторым филиалам или только определённым ролям. HR прикладывает статьи к ответам на [обращения](desk.md).

## Как пользоваться
- **Сервіси → База знань** (`/knowledge`): поиск по заголовку и тексту, категории-чипы, клик по `#тегу`. Статья
  (`/knowledge/:id`) — текст, «Це було корисно?» 👍/👎 (голос можно поменять).
- Админ (HR): «Нова стаття» / «Редагувати» (`/admin/knowledge/new`, `/admin/knowledge/:id`) — заголовок, категория,
  теги через запятую, статус «Чернетка/Опубліковано», «Хто бачить» (усі / філії / ролі), текст в Markdown, история
  версий с «Підставити в редактор». Черновики видят только админы.

## Как устроено
Бэкенд — `backend/app/Modules/Knowledge`, маршруты `/api/knowledge/*`, `auth:sanctum` + `EnsureUserIsActive`;
запись — gate `knowledge-manage` = `PeopleScope::isAdmin`.

### Таблицы (`Database/Migrations/2026_10_05_300001_create_knowledge_tables.php`)
| Таблица | Колонки |
|---|---|
| `kb_categories` | `name, emoji?, position` |
| `kb_articles` | `category_id?, title, body_md, body_html, tags json, audience json, status (draft\|published), version, author_id?, updated_by?, published_at?` |
| `kb_article_versions` | `article_id, version, title, body_md, edited_by?, created_at` — `unique(article_id, version)` |
| `kb_votes` | `article_id, user_id, helpful` — `unique(article_id, user_id)` |

### Безопасный HTML — тот же рендерер, что в Documents
`body_html` считается **при сохранении** `Documents\Support\MarkdownRenderer::toHtml` (DRY): сырой HTML экранируется,
`javascript:`/`data:` ссылки выбрасываются. Фронтенд выводит готовый `html` через `[innerHTML]` (санитайзер Angular —
второй слой). Markdown в ответе — только редакторам.

### Аудитория (`Support/Audience`, чистая)
`{"type":"all"}` | `{"type":"branches","ids":[…]}` (филиал карточки сотрудника или рабочие филиалы пользователя из
`AccessibleBranches`) | `{"type":"roles","roles":[…]}` (роли Spatie). Админ видит всё. Статья вне аудитории и
черновик — 404 (голосовать тоже нельзя). Фильтр выполняется в PHP после выборки (≤ 1000 статей) — без JSON-SQL
под конкретную СУБД.

### Поиск
`?q=` — `title`/`body_md` через `Sql::whereContainsCi` (регистр не важен; на MySQL поиск шире — без учёта диакритики, см. ниже), `%` и `_` экранируются
(`ESCAPE '!'`) — ищутся буквально. `?category_id=`, `?tag=` (теги хранятся в нижнем регистре, без дублей).

### Версии
Изменение заголовка или текста сохраняет предыдущий вариант в `kb_article_versions` и увеличивает `version`; смена
тегов/аудитории/статуса версию не создаёт. `GET /api/knowledge/articles/{id}/versions` — только админам.

### Порт для других модулей
`Contracts/PublishedArticles::titles(ids)` — заголовки **только опубликованных** статей (Desk проверяет ссылку в ответе
и показывает заголовок). Черновик через порт не виден.

### API
`GET categories`, `POST/PATCH categories` (HR); `GET articles?q=&category_id=&tag=`, `GET articles/{id}`,
`POST articles/{id}/vote {helpful}`; `POST articles`, `PATCH articles/{id}`, `GET articles/{id}/versions` (HR).

### Фронтенд
`frontend/src/app/features/knowledge`: `knowledge.page`, `article.page`, `editor.page` (`audienceOf`),
`knowledge.service`, `knowledge.model` (`parseTags`, `helpfulPercent`).

**Вид (рестайл C «Маршрут», 2026-10-02).** Список статей — строки на «треке» с hover, «Чернетка» — нейтральная пилюля, теги — моно, на телефоне 44px (цель касания); пустой список — `.app-empty`. Статья — ширина чтения 52rem, нажатая кнопка голоса — линия и текст бренда. Тест вида — `features/knowledge/knowledge.restyle.spec.ts` (контракт стилей: только токены темы, без hex, линии 1.5px, без «бледности» через opacity).

### Общие хелперы Core (2026-10-02)
- поиск `LIKE` экранирует `%`, `_` и сам символ экранирования через `Core\Support\Database\Like` (`ESCAPE '!'`, `Like::contains(…, Like::PORTABLE)`), регистр не важен (`lower(..) like`, collation `utf8mb4_0900_ai_ci`);
- gate `knowledge-manage` задаётся `ModuleServiceProvider::defineRoleGate(…, UserRole::hrStaff())`: активный superadmin, admin или hr_manager — тот же набор, что `PeopleScope::isAdmin` (модуль больше не импортирует `PeopleScope` ради gate);
- текущий пользователь в контроллерах — общий трейт `Core\Http\Concerns\ResolvesActor` вместо приватной копии `actor()`.

Поведение API не менялось; подробности — [core.md](core.md), раздел «Общие хелперы модулей».

### Общие примитивы фронта
Общий код фронта лежит в `frontend/src/app/core` ([core.md](core.md)); фича его только вызывает.
- Ошибки API → i18n-ключ: `knowledgeErrorKey` — обёртка над общим `apiErrorKey` (`core/api/api-error.ts`) со своими кодами, списком статусов и запасным ключом; набор ключей и тексты прежние.
- Короткие уведомления (toast) — `NotifyService.show(key, { params?, duration? })` из `core/ui/notify.service.ts` вместо своего `toast()` с `MatSnackBar`; тексты, длительности и доступность (вежливая live-область snack bar) прежние.
- HTTP-сервис фичи снимает обёртку ответа `{ data }` общим оператором `unwrapData()` (`core/api/unwrap-data.ts`, тип `DataEnvelope<T>` из `core/api/api.model.ts`) вместо своего `map((r) => r.data)`; параметры запроса без пустых значений — `toParams` из `core/api/http-params.ts`, страница списка — `Paged<T>` оттуда же. Контракт API не менялся.

### Зависимости через контракты (2026-10-08)
- `KnowledgeService` определяет редактора и филиал сотрудника через контракт People `PeopleAccess`. Тест — `tests/Unit/Knowledge/KnowledgePeopleAccessTest.php`.

## Как проверить
`php artisan test --filter=Knowledge` — запись только админам, черновики скрыты (404), очистка XSS (`<script>`,
`javascript:`, `<img onerror>`), аудитория по филиалу и роли, поиск без учёта регистра и с буквальными `%`/`_`,
версии, голоса.


> Роли: «админ (HR)» здесь — это `UserRole::hrStaff()`: `superadmin`, `admin` и `hr_manager` (с 2026-10-09, [auth.md](auth.md)).

## Доступ к модулю

Ключ модуля `knowledge`. Суперадмин может выключить модуль для всей компании или скрыть его от части ролей на странице «Адміністрування → Модулі». По умолчанию: включён, роли — все роли (как и до появления выключателя). Выключенный модуль отвечает 403 `module_disabled`, его фоновые задачи пропускаются, данные не удаляются. Подробнее — [modules-access.md](modules-access.md).
MySQL compatibility: article Markdown, rendered HTML and version history use `LONGTEXT` so accepted Unicode bodies exceeding 64 KiB roundtrip.

**Поиск статей (2026-10-08).** `Sql::whereContainsCi`: `lower(title|body_md) like ? escape '!'`, регистр (включая кириллицу) не важен, `%`/`_`/`!` в запросе ищутся буквально; проверка — `KnowledgeApiTest::test_search_folds_cyrillic_case_on_every_driver` в job `tests` на MySQL 8.4 ([ADR 0011](../adr/0011-mysql-only.md)). Поиск не различает и диакритику латиницы (`é` = `e`, collation `utf8mb4_0900_ai_ci`); фиксирует `PortableSqlTest::test_contains_diacritics_known_divergence_mysql_is_wider`.
