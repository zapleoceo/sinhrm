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
Enum `Enums\UserRole`: `superadmin`, `admin`, `recruiter`, `viewer` (роли Spatie, guard `web`, создаются
data-миграцией `2026_09_26_000002_create_default_roles`). Enum `Enums\UserStatus`: `active` | `blocked`.
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
`tests/Unit/Auth/AuthServiceTest.php`, `tests/Unit/Auth/SocialiteGoogleIdentityProviderTest.php`,
`frontend/.../login-error.spec.ts`.

Вручную (preview/prod):
```bash
curl -i https://sinhrm.vercel.app/api/auth/me                 # 401 без сессии
curl -i https://sinhrm.vercel.app/api/auth/google/redirect     # 302 на accounts.google.com
curl -i "https://sinhrm.vercel.app/api/auth/google/callback?error=access_denied"  # 302 /login?error=oauth_failed
```
Полный вход — в браузере аккаунтом из списка тестовых пользователей Google.

## Гость без авторизации
Любой защищённый эндпоинт отвечает гостю `401 {"message":"Unauthenticated."}` — и для запроса без
`Accept: application/json` тоже (`redirectGuestsTo(null)` в `bootstrap/app.php`; веб-маршрута `login` в API нет).
Проверено на проде: до исправления `curl https://sinhrm.vercel.app/api/auth/me` → 500 «Route [login] not defined».
