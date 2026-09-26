# Модуль Auth

## Что это и зачем
Вход в SinHRM через рабочий аккаунт Google — без отдельных паролей. Войти может **только приглашённый** человек:
суперадмин заранее добавляет его e-mail в админке «Пользователи». Единственное исключение — адрес из переменной
`SUPERADMIN_EMAIL`: при первом входе он сам становится суперадмином (так система «запускается» на пустой базе).
Модуль также хранит роли пользователей и выбранный язык интерфейса.

## Как пользоваться
1. Откройте `https://sinhrm.vercel.app` → страница входа → «Продолжить с Google».
2. Выберите рабочий аккаунт. Если вас пригласили — попадёте на главную; если нет — увидите понятное сообщение.
3. Выход — меню пользователя справа вверху → «Выйти». Язык меняется там же и запоминается в профиле.

**Важно: Google OAuth-приложение сейчас в режиме Testing.** Google пускает только адреса из списка тестовых
пользователей. Кто не в списке — получит ошибку от Google ещё до SinHRM. Добавить: Google Cloud Console → проект
`sinhrm` → Google Auth Platform → **Audience** → Test users → Add users. Приглашение в SinHRM всё равно нужно отдельно.

## Как устроено
### Роли и статусы
Простыми словами: у каждого пользователя одна **глобальная роль** — она решает, какие разделы ему открыты. Кроме неё
бывают **контекстные роли** — это не роль в списке, а назначение на конкретную вещь: «нанимающий менеджер этой вакансии»,
«интервьюер этого кандидата», «руководитель этих сотрудников». Набор повторяет практику PeopleForce, HiBob, BambooHR,
Personio, Workable и Greenhouse (разбор — `D:/Projects/HRM/docs/roles-research.md`, раздел 8).

| Роль (`Enums\UserRole`) | Кто это | Что может |
|---|---|---|
| `superadmin` | владелец системы | всё, включая пользователей, интеграции, почту |
| `admin` | администратор | всё, кроме управления пользователями и интеграциями; справочники, воронки, скрипты |
| `hr_manager` | HR-менеджер | HR-разделы: люди, отпуска, табели, кейсы, опросы, оценка, документы, воркфлоу, база знаний, техника, заявки на подбор; все филиалы; рекрутинг — только чтение |
| `recruiter` | рекрутер | рекрутинг в своих филиалах: вакансии, кандидаты, заявки |
| `employee` | сотрудник | самообслуживание: свой профиль, отпуска, документы, опросы, заявки; рекрутинг — только через контекстную роль |
| `viewer` | наблюдатель | рекрутинг своих филиалов только на чтение |

**Доступ к модулям.** Поверх ролей суперадмин может выключить модуль или скрыть его от ролей («Адміністрування →
Модулі», [modules-access.md](modules-access.md)). Это только сужает доступ. Контекстная роль проходит по базовой роли
человека (обычно `employee`); пользователь без глобальной роли считается `employee`. `GET /api/auth/me` возвращает
`modules` — ключи доступных модулей.

Контекстные роли (не хранятся в таблице ролей):
- **нанимающий менеджер** — поле `vacancies.hiring_manager_id`; видит и ведёт только эту вакансию ([recruiting.md](recruiting.md), «Команда найму»);
- **интервьюер** — таблица `application_interviewers`; видит только кандидата этой заявки;
- **руководитель** — вычисляется из `employees.manager_id` (модуль People), отдельно не назначается.

Группы ролей живут в одном месте — статические методы enum: `hrStaff()` (superadmin, admin, hr_manager — действуют как
HR; на них опираются `PeopleScope::isAdmin` и все gate'ы `*-manage` модулей People/TimeOff/Time/Desk/Pulse/Perform/
Documents/Workflows/Knowledge/Assets/HiringRequests, а также `BranchAccess` — «все филиалы»), `recruitingWriters()`
(superadmin, admin, recruiter), `recruitingReaders()` (hrStaff + recruiter + viewer), `valuesOf()` для Spatie.
Фронтенд повторяет это в `core/auth/auth.model.ts` (`HR_STAFF_ROLES`, `isHrStaff`).

Роли Spatie (guard `web`) создаются data-миграциями `2026_09_26_000002_create_default_roles` и
`2026_10_09_100001_standardize_roles`. Вторая добавляет `hr_manager` и `employee`, имена старых ролей не меняет,
а пользователю без роли выдаёт `employee`. Откат (`down`): держатели `hr_manager` получают `admin` (до этого HR был
админом — доступ не пропадает), назначения `employee` снимаются, обе роли удаляются. Имена ролей в миграции — строки,
а не enum: старая миграция не должна меняться вслед за кодом.

Enum `Enums\UserStatus`: `active` | `blocked`.
Язык — `Enums\AppLocale`: `uk` (по умолчанию), `ru`, `en`.

Миграция `2026_09_26_000001_add_auth_fields_to_users_table`: `password` может быть пустым; новые поля
`google_id` (уникальный), `avatar_url`, `status` (индекс), `locale`, `last_login_at`, `invited_by` (FK на `users`).

### Правила входа (`Services\AuthService::handleGoogle`)
1. Google не подтвердил e-mail → отказ `email_unverified`.
2. Ищем пользователя по `google_id`, затем по e-mail (без учёта регистра).
3. Не найден: e-mail = `SUPERADMIN_EMAIL` → создаём активного пользователя с ролью `superadmin`; иначе отказ `not_invited`.
4. Найден, но `blocked` → отказ `blocked`.
5. Иначе сохраняем `google_id`, аватар, `last_login_at`; `Auth::login(remember)` + новая сессия → редирект на `/`.

Отказ → редирект `/login?error=<код>`. В URL и логах только код причины — ни e-mail, ни токенов.
Ошибка OAuth (неверный `state`, отказ в согласии, сбой Google) → `oauth_failed` и `warning` в лог с классом исключения.

### Эндпоинты (`/api/auth`)
| Метод и путь | Доступ | Ответ |
|---|---|---|
| `GET google/redirect` | все | 302 на Google (scopes `openid email profile`) |
| `GET google/callback` | Google | 302 на `/` или `/login?error=not_invited\|blocked\|email_unverified\|oauth_failed` |
| `GET me` | вход | `{id, name, email, avatar_url, locale, roles[], status}`; гость → 401 |
| `PATCH me/locale` `{locale}` | вход | профиль; язык не из `uk,ru,en` → 422 |
| `POST logout` | вход | 204, сессия уничтожена |

Заблокированный пользователь с живой сессией на следующем запросе получает 403 `{"message":"blocked"}`
и разлогинивается (`Http\Middleware\EnsureUserIsActive`).

### Сессия и cookie
Sanctum в режиме SPA: `bootstrap/app.php` → `$middleware->statefulApi()`. Фронт и API на одном домене
(`sinhrm.vercel.app`, rewrite `/api/*` и `/sanctum/*` в `frontend/vercel.json`), поэтому работают обычные cookie.
Сессии в Postgres (`SESSION_DRIVER=database`), на Vercel `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`.
Список доменов SPA — `SANCTUM_STATEFUL_DOMAINS` (по умолчанию `sinhrm.vercel.app` + localhost).
Маршруты OAuth лежат в `routes.web.php` (группа `web`): callback приходит с сайта Google, и только так у него
гарантированно есть сессия для проверки `state`. Редиректы на SPA — относительные (`/`, `/login?...`), потому что
API отвечает через домен фронта.

### Слои
`Http/Controllers` (`GoogleAuthController`, `MeController`) → `Http/Requests/UpdateLocaleRequest` →
`Services/AuthService` → `Contracts/UserRepository` (`Repositories/EloquentUserRepository`).
Google спрятан за `Contracts/GoogleIdentityProvider` (`Services/SocialiteGoogleIdentityProvider`), в тестах — фейк.
`UserRepository::find(id)` нужен другим модулям, чтобы найти пользователя фоновой задачи (почтовый агент, авто-импорт из
Google Sheets действуют от имени суперадмина, подключившего Google). Подключение Gmail/Calendar/Sheets — **отдельный** OAuth-поток
того же клиента с другим redirect URI, он не входит в систему и не меняет сессию: [google-workspace.md](google-workspace.md).

### Настройки
`config/services.php` → `google`: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` (Vercel env, в репозитории пусто),
`GOOGLE_REDIRECT_URI` (по умолчанию `https://sinhrm.vercel.app/api/auth/google/callback`).
`config/auth.php` → `superadmin_email` из `SUPERADMIN_EMAIL`.

### Фронтенд
`features/auth/login.page.ts` — карточка входа; код `?error` переводится в сообщение (`login-error.ts`,
неизвестный код → общее сообщение). Сессия, guards и язык — в `core/` (см. [core.md](core.md)).

## Как проверить
Тесты: `tests/Feature/Auth/GoogleCallbackTest.php` (суперадмин, приглашённый, not_invited, blocked,
email_unverified, oauth_failed), `tests/Feature/Auth/MeTest.php` (me, 401, 403 blocked, locale, logout),
`tests/Unit/Auth/AuthServiceTest.php`, токены — `tests/Feature/Recruiting/ExtensionApiTest.php`, `tests/Unit/Auth/SocialiteGoogleIdentityProviderTest.php`,
`frontend/.../login-error.spec.ts`.

Вручную (preview/prod):
```bash
curl -i https://sinhrm.vercel.app/api/auth/me                 # 401 без сессии
curl -i https://sinhrm.vercel.app/api/auth/google/redirect     # 302 на accounts.google.com
curl -i "https://sinhrm.vercel.app/api/auth/google/callback?error=access_denied"  # 302 /login?error=oauth_failed
```
Полный вход — в браузере аккаунтом из списка тестовых пользователей Google.

## Токены браузерного расширения
Кроме cookie-сессии API принимает **персональные токены Sanctum** — сейчас только для расширения «SinHRM Clipper»
([extension.md](extension.md)). Пользователь создаёт токен в SPA (`POST /api/me/extension-token`, нужна сессия): имя
`extension`, ability `clipper`, срок 90 дней, один активный (новый удаляет старый), открытый текст — только в ответе на
создание (в БД — SHA-256). `last_used_at` обновляет Sanctum.

**Токен работает только на `/api/clipper/*`.** Стандартный guard Sanctum принял бы любой действующий токен на любом
маршруте `auth:sanctum`, поэтому в `RecruitingServiceProvider::bootExtensionTokens()` задан
`Sanctum::authenticateAccessTokensUsing`: токен аутентифицирует запрос к `/api/clipper/*` только с ability `clipper`,
а к любому другому маршруту — только с ability `full` (такие токены не выдаются). Итог: токен расширения на
`/api/candidates`, `/api/users`, `/api/auth/me`, `/api/me/extension-token` → 401; маршруты clipper дополнительно
проверяют `CheckAbilities:clipper`. Сессия (cookie SPA) не затронута. У `User` подключён `HasApiTokens`.
CORS (`config/cors.php`) открыт только для `api/clipper/*`, только для origin `chrome-extension://<id>`, без credentials.
Тест: `tests/Feature/Recruiting/ExtensionApiTest.php` (без этого колбэка токен получал 200 на `/api/candidates` — проверено).

## Гость без авторизации
Любой защищённый эндпоинт отвечает гостю `401 {"message":"Unauthenticated."}` — и для запроса без
`Accept: application/json` тоже (`redirectGuestsTo(null)` в `bootstrap/app.php`; веб-маршрута `login` в API нет).
Проверено на проде: до исправления `curl https://sinhrm.vercel.app/api/auth/me` → 500 «Route [login] not defined».

## Доступ к модулю

Ключ модуля `auth`. Это **базовый** модуль: его нельзя выключить или ограничить по ролям на странице «Адміністрування → Модулі». Подробнее — [modules-access.md](modules-access.md).
