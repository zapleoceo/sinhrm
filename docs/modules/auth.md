# Модуль Auth

## Что это и зачем
Вход в SinHRM через рабочий аккаунт Google — без отдельных паролей. Войти может **только приглашённый** человек:
суперадмин заранее добавляет его e-mail в админке «Пользователи». Единственное исключение — адрес из переменной
`SUPERADMIN_EMAIL`: при первом входе он сам становится суперадмином (так система «запускается» на пустой базе).
Дальше суперадмин может назначить суперадмином и другого человека (и снять эту роль) — с предупреждением и записью в
журнал, только для чужой учётной записи и никогда не оставляя систему без активного суперадмина ([users.md](users.md), HRM-84).
Модуль также хранит роли пользователей и выбранный язык интерфейса.

## Как пользоваться
1. Откройте `https://sinhrm.vercel.app` → страница входа → «Продолжить с Google».
2. Выберите рабочий аккаунт. Если вас пригласили — попадёте на главную; если нет — увидите понятное сообщение.
3. Выход — меню пользователя справа вверху → «Выйти». Язык меняется там же и запоминается в профиле.

**Важно: Google OAuth-приложение сейчас в режиме Testing.** Google пускает только адреса из списка тестовых
пользователей. Кто не в списке — получит ошибку от Google ещё до SinHRM. Добавить: Google Cloud Console → проект
`sinhrm` → Google Auth Platform → **Audience** → Test users → Add users. Приглашение в SinHRM всё равно нужно отдельно.

## Как устроено
- Фронт (2026-10-08): список кодов отказа входа (`LOGIN_ERROR_CODES`, `login-error.ts`) больше не экспортируется — снаружи нужен только `loginErrorKey`; неиспользуемый тип `LoginErrorCode` удалён (knip).
### Роли и статусы
Простыми словами: у каждого пользователя есть одна или **несколько глобальных ролей** — они решают, какие разделы
ему открыты (права ролей складываются; см. «Працювати як» ниже). Роли выдаёт суперадмин в [users.md](users.md). Кроме неё
бывают **контекстные роли** — это не роль в списке, а назначение на конкретную вещь: «нанимающий менеджер этой вакансии»,
«интервьюер этого кандидата», «руководитель этих сотрудников». Набор повторяет практику PeopleForce, HiBob, BambooHR,
Personio, Workable и Greenhouse.

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
| `GET me` | вход | `{id, name, email, avatar_url, locale, approval_emails, roles[] (назначенные), active_role, effective_roles[], status, modules[]}`; гость → 401 |
| `PUT active-role` `{role}` | вход, в любой роли | «Працювати як»: профиль как у `GET me`; роль не из назначенных (или у аккаунта одна роль) → 422; `null` → все роли; 30 запросов/мин |
| `PATCH me/locale` `{locale}` | вход | профиль; язык не из `uk,ru,en` → 422 |
| `PATCH me/notifications` `{approval_emails: bool}` | вход | профиль; выключатель писем о согласованиях («Мій профіль»); не boolean → 422 |
| `POST logout` | вход | 204, сессия уничтожена |

Заблокированный пользователь с живой сессией на следующем запросе получает 403 `{"message":"blocked"}`
и разлогинивается (`Http\Middleware\EnsureUserIsActive`).

PAT и Google login блокируют user row и проверяют свежий active status и захваченную
`credential_version`. Block увеличивает версию; unblock её не сбрасывает. Старый actor после
block не получает PAT (403 blocked), после block/unblock — 403 credentials_revoked.
Замена PAT выполняется после проверок в той же транзакции: stale grant не удаляет новый токен.
Google grant со старой версией отклоняется как oauth_failed.

Google login создаёт remember-token и сохраняет захваченную версию в web session под user lock.
Cookie «запомнить меня» живёт **14 дней** (`config/auth.php` → `guards.web.remember`, env `AUTH_REMEMBER_MINUTES`),
а не ~400 дней по умолчанию фреймворка (аудит безопасности 2026-10). Тест — `GoogleCallbackTest::test_remember_cookie_lives_fourteen_days_not_the_framework_default`.
Поздняя DB-запись старой сессии после block/unblock не восстанавливает доступ: middleware
отклоняет durable web credential со старым/отсутствующим stamp (401 credentials_revoked).
Обычный запрос не обновляет stamp. Валидный remember-cookie является новым grant с версией
загруженного пользователя; старый cookie отозван обнулением remember-token.
Миграция 2026_10_09_180000_add_user_credential_version нужна перед выпуском кода.
Legacy durable sessions без stamp требуют нового Google login. Новый вход после unblock разрешён.
Выполняющиеся запросы не отменяются. Feature regressions используют настоящие DI repositories:
`tests/Feature/Auth/TokenGrantRevocationTest`, `tests/Feature/Auth/GoogleCallbackTest`, `tests/Feature/Users/UserCredentialRevocationTest`.

### «Працювати як» (активная роль)
Простыми словами: если у аккаунта **несколько** глобальных ролей, в меню пользователя (аватар внизу слева) можно
выбрать, в какой из них сейчас работать, или «Усі ролі» (по умолчанию — как раньше, права всех ролей вместе).
Пока выбрана одна роль, **весь сервер** проверяет права только по ней: меню и модули, права (gates и policies),
матрица ролей модулей, счётчики в меню, отчёты, уровни доступа в «Людях», массовые действия. Выбор может только
**сузить** права — дать роль, которой у человека нет, нельзя.

- Где хранится: в серверной сессии (ключ `active_role`), не в базе. Другое устройство или новый вход — снова
  «Усі ролі»; выход (`POST logout`) выбор стирает. Токены (расширение, MCP) сессии не имеют и всегда работают со всеми ролями.
- Как применяется: `Http\Middleware\ApplyActiveRole` (в группе `api`, до любой проверки прав) вызывает
  `User::actAs($role)`. Тот заменяет у пользователя загруженные роли Spatie одной активной, поэтому `hasRole`,
  `hasAnyRole`, `getRoleNames` и всё, что на них построено, видят только её — одно место, без проверок по коду.
  `User::assignedRoles()` — назначенные роли, `User::effectiveRoles()` — роли, по которым сейчас идут проверки.
- Если активную роль у человека забрали (или осталась одна роль), выбор стирается из сессии на ближайшем запросе —
  работают все оставшиеся роли; если роль потом вернут, сузится только после нового выбора.
- Новый вход через Google всегда начинается с «Усі ролі»: после смены сессии ключ `active_role` удаляется.
- Переключиться обратно можно всегда: `PUT active-role` не закрыт ни ролью, ни модулем.
- Контекстные роли (нанимающий менеджер вакансии, интервьюер отклика, руководитель подчинённых) берутся из
  назначений, а не из глобальной роли, поэтому работают как раньше и **добавляются** к активной роли — точно так же,
  как сегодня у сотрудника.
- Управление пользователями смотрит на **назначенные** роли: правило «последний суперадмин» и список пользователей
  читают роли из базы, выбор в сессии на них не влияет ([users.md](users.md)).
- Журнал действий: запись, сделанная в выбранной роли, получает `meta.acting_role` ([audit.md](audit.md)).

Фронт: секция «Працювати як» в меню пользователя ([shell.md](shell.md)); в `core/auth` поле `roles` — это
действующие (effective) роли, назначенные — `assigned_roles` ([core.md](core.md)).

### Сессия и cookie
Sanctum в режиме SPA: `bootstrap/app.php` → `$middleware->statefulApi()`. Фронт и API на одном домене
(`sinhrm.vercel.app`, rewrite `/api/*` и `/sanctum/*` в `frontend/vercel.json`), поэтому работают обычные cookie.
Сессии в БД приложения (`SESSION_DRIVER=database`, MySQL 8.4), на Vercel `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`.
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
Другие модули не строят запросы к `users` сами: получатель письма (`GoogleWorkspace\Services\MailUserNotifier`) ищется
через `UserRepository::find()`, имена авторов версий промптов (Ai) — через `UserRepository::namesByIds()` (id → имя,
отсутствующие id пропускаются). Тест — `tests/Feature/Auth/UserRepositoryTest.php`.

### Настройки
`config/services.php` → `google`: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` (Vercel env, в репозитории пусто),
`GOOGLE_REDIRECT_URI` (по умолчанию `https://sinhrm.vercel.app/api/auth/google/callback`).
`config/auth.php` → `superadmin_email` из `SUPERADMIN_EMAIL`.

### Фронтенд
`features/auth/login.page.ts` — карточка входа; код `?error` переводится в сообщение (`login-error.ts`,
неизвестный код → общее сообщение, плашка `role=alert` внутри карточки). В карточке сверху — логотип
(`<app-logo>`) и компактный переключатель языка uk|ru|en; кнопка Google с фирменной «G» ведёт на
`/api/auth/google/redirect`. Дизайн — [design-direction.md](../architecture/design-direction.md) §8 и §4.2.
Сессия, guards и язык — в `core/` (см. [core.md](core.md)).

**Вид (рестайл C «Маршрут», 2026-10-02).** Фон — статичное мягкое свечение бренда и бирюзы на `--app-canvas` и сетка с
радиальной маской (дрейфующие пятна убраны: в бюджете эффектов только одна «трасса» и одна «поп»). Карточка — язык линий:
рамка 1.5px, без тени, «стекло» с непрозрачным фолбэком. Над заголовком — декоративный маршрут (`aria-hidden`) из пяти
станций `.app-station` по типам этапов `LOGIN_ROUTE` = new → screen → interview → offer → hire (цвета — токены
`--app-stage-*`, последняя залита, с ореолом). Анимации: заливка линии прорисовывается один раз (`scaleX`, 600мс —
«трасса»), карточка появляется (`opacity` + `transform`, 280мс — «поп»); при `prefers-reduced-motion` обе выключены.
Кнопка Google — пилюля 48px с рамкой `outline`, hover — рамка цветом текста и `translateY(-1px)` за 120мс. Ошибка
`?error` — плашка `--app-bad-*` с квадратным маркером (не только цвет). Переключатель языка — 32px на десктопе и
**44px на ≤ 600px** (цель касания; Material 22 читает именно `--mat-button-toggle-height`, замер в Playwright на 390px —
44px). Фокус с клавиатуры на кнопке Google — глобальное кольцо `--app-focus-ring` (отступ 2px), но толщиной 3px (толще глобальных 2px: это
единственное действие страницы). Тест — `login.page.spec.ts` (станции, ссылка Google, плашка ошибки, кольцо фокуса, 44px).

## Как проверить
Тесты: `tests/Feature/Auth/GoogleCallbackTest.php` (суперадмин, приглашённый, not_invited, blocked,
email_unverified, oauth_failed), `tests/Feature/Auth/MeTest.php` (me, 401, 403 blocked, locale, logout),
`tests/Unit/Auth/AuthServiceTest.php`, токены — `tests/Feature/Recruiting/ExtensionApiTest.php`, `tests/Unit/Auth/SocialiteGoogleIdentityProviderTest.php`,
`frontend/.../login-error.spec.ts`, `frontend/.../login.page.spec.ts`.

Вручную (preview/prod):
```bash
curl -i https://sinhrm.vercel.app/api/auth/me                 # 401 без сессии
curl -i https://sinhrm.vercel.app/api/auth/google/redirect     # 302 на accounts.google.com
curl -i "https://sinhrm.vercel.app/api/auth/google/callback?error=access_denied"  # 302 /login?error=oauth_failed
```
Полный вход — в браузере аккаунтом из списка тестовых пользователей Google.

## Персональные токены (расширение, MCP)
Кроме cookie-сессии API принимает **персональные токены Sanctum** с одной ability: расширение «SinHRM Clipper»
([extension.md](extension.md), ability `clipper`) и MCP-токен помощника ([assistant.md](assistant.md), ability `mcp`).
Выдачу, статус и отзыв делает общий `Services/PersonalTokens` (+ `Contracts/PersonalTokenRepository`,
`DTO/TokenKind`, `DTO/PersonalTokenStatus`): модуль описывает вид токена (имя, ability, срок, событие лога), токен
создаётся в SPA сессией, срок 90 дней, один активный на вид (новый удаляет старый), открытый текст — только в ответе на
создание (в БД — SHA-256). `last_used_at` обновляет Sanctum.

**Токен работает только на своих путях.** Стандартный guard Sanctum принял бы любой действующий токен на любом
маршруте `auth:sanctum`, поэтому `AuthServiceProvider::boot()` задаёт `Sanctum::authenticateAccessTokensUsing` через
`Support/TokenScopes`: модули регистрируют «шаблон пути → ability» (`api/clipper/*` → `clipper` в Recruiting,
`api/mcp` → `mcp` в Assistant); к любому другому маршруту токен допускается только с ability `full` (такие токены не
выдаются). Итог: токен расширения на `/api/candidates`, `/api/users`, `/api/auth/me`, `/api/me/extension-token`,
`/api/mcp` → 401, MCP-токен везде, кроме `/api/mcp`, → 401; маршруты clipper дополнительно проверяют
`CheckAbilities:clipper`. Сессия (cookie SPA) не затронута. У `User` подключён `HasApiTokens`.
CORS (`config/cors.php`) открыт только для `api/clipper/*`, только для origin `chrome-extension://<id>`, без credentials.
Тесты: `tests/Feature/Recruiting/ExtensionApiTest.php` (без этого колбэка токен получал 200 на `/api/candidates` —
проверено), `tests/Feature/Assistant/McpServerTest.php`, `tests/Unit/Auth/TokenScopesTest.php`.

## Гость без авторизации
Любой защищённый эндпоинт отвечает гостю `401 {"message":"Unauthenticated."}` — и для запроса без
`Accept: application/json` тоже (`redirectGuestsTo(null)` в `bootstrap/app.php`; веб-маршрута `login` в API нет).
Проверено на проде: до исправления `curl https://sinhrm.vercel.app/api/auth/me` → 500 «Route [login] not defined».

## Доступ к модулю

Ключ модуля `auth`. Это **базовый** модуль: его нельзя выключить или ограничить по ролям на странице «Адміністрування → Модулі». Подробнее — [modules-access.md](modules-access.md).

`PATCH /api/auth/me/notifications {approval_emails: bool}` — вимикач листів про погодження («Мій профіль»); `GET /me` повертає `approval_emails`.

Upgrade safeguard: restoring a legacy Blocked account with credential_version=0 atomically revokes its old sessions/PAT/remember-token and advances version before Active. Normal unblock after a new explicit block changes status only. Upgrade-like feature regression preserves healthy users and rejects all old credentials without a new block first. CI pending.
MySQL 8.4 only (ADR 0011, 2026-10-08): `google_id` uses `utf8mb4_bin` so distinct Google account identifiers do not merge; the migration no longer branches by driver. Test: `tests/Feature/Auth/GoogleIdMysqlSchemaTest` (binary collation, unique index, `Gid-A` ≠ `Gid-a`, duplicate rejected). The pre-cutover production release is frozen on a separate legacy branch (docs/guides/deploy.md).

Статанализ: Larastan 3.12.3 выводит тип `Auth::guard('web')` как `SessionGuard`, из-за чего проверки `instanceof SessionGuard` в `EnsureUserIsActive` помечаются как `instanceof.alwaysTrue`. Проверки оставлены (guard настраивается конфигом, а `viaRemember()`/`logoutCurrentDevice()` есть только у сессионного guard) и снабжены `@phpstan-ignore instanceof.alwaysTrue`. Поведение не менялось.
