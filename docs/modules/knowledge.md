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
| `kb_articles` | `category_id?, title, body_md, body_html, tags jsonb, audience jsonb, status (draft\|published), version, author_id?, updated_by?, published_at?` |
| `kb_article_versions` | `article_id, version, title, body_md, edited_by?, created_at` — `unique(article_id, version)` |
| `kb_votes` | `article_id, user_id, helpful` — `unique(article_id, user_id)` |

### Безопасный HTML — тот же рендерер, что в Documents
`body_html` считается **при сохранении** `Documents\Support\MarkdownRenderer::toHtml` (DRY): сырой HTML экранируется,
`javascript:`/`data:` ссылки выбрасываются. Фронтенд выводит готовый `html` через `[innerHTML]` (санитайзер Angular —
второй слой). Markdown в ответе — только редакторам.

### Аудитория (`Support/Audience`, чистая)
`{"type":"all"}` | `{"type":"branches","ids":[…]}` (филиал карточки сотрудника или рабочие филиалы пользователя из
`AccessibleBranches`) | `{"type":"roles","roles":[…]}` (роли Spatie). Админ видит всё. Статья вне аудитории и
черновик — 404 (голосовать тоже нельзя). Фильтр выполняется в PHP после выборки (≤ 1000 статей) — одинаково на
Postgres и SQLite.

### Поиск
`?q=` — `title`/`body_md` через `ILIKE` на Postgres и `LIKE` на SQLite (регистр не важен), `%` и `_` экранируются
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

## Как проверить
`php artisan test --filter=Knowledge` — запись только админам, черновики скрыты (404), очистка XSS (`<script>`,
`javascript:`, `<img onerror>`), аудитория по филиалу и роли, поиск без учёта регистра и с буквальными `%`/`_`,
версии, голоса.


> Роли: «админ (HR)» здесь — это `UserRole::hrStaff()`: `superadmin`, `admin` и `hr_manager` (с 2026-10-09, [auth.md](auth.md)).
