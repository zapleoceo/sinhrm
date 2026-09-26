# Модуль Users

## Что это и зачем
Админка пользователей: кто может войти в SinHRM и с какими правами. Здесь суперадмин приглашает людей,
меняет им роль и блокирует доступ. Удаления нет — человека блокируют, история остаётся.

## Как пользоваться
Меню слева → «Адміністрування → Користувачі» (видно только суперадмину).
- **Запросити** — e-mail, имя, роль (`admin`, `recruiter`, `viewer`). Человек сразу активен, пароля нет:
  он входит через Google с этим же e-mail (и должен быть в тестовых пользователях Google, см. [auth.md](auth.md)).
- **Роль** — выпадающий список в строке. **Заблокувати / Розблокувати** — кнопка в строке.
  Изменения применяются сразу; если сервер отказал — строка возвращается как была и показывается причина.
- Поиск по имени/e-mail, фильтры по роли и статусу, постраничный вывод.
- **Філії** — мультивыбор в строке (только для рекрутера и наблюдателя; у суперадмина и админа — «Усі філії»,
  они филиалами не ограничены). Сохраняется при закрытии списка; предлагаются только активные филиалы.
- Свою роль, статус и филиалы менять нельзя; последнего активного суперадмина нельзя понизить или заблокировать.
- **Зробити обробником Safe Speak / Прибрати з обробників** — кнопка в строке суперадмина и админа: только такие
  пользователи читают анонимные сообщения ([safe-speak.md](safe-speak.md)). Флаг можно поставить и себе (это не
  смена роли); при понижении до рекрутера/наблюдателя флаг снимается автоматически.

## Как устроено
### Доступ
Gate `manage-users` (`Providers\UsersServiceProvider::MANAGE_USERS`): активный пользователь с ролью `superadmin`.
Назначаемые роли (приглашение и смена): `admin`, `hr_manager`, `recruiter`, `employee`, `viewer` — что каждая значит, см.
[auth.md](auth.md) «Роли и статусы». В списке пользователей есть фильтр по роли, подписи ролей переведены (uk/ru/en,
ключи `roles.*`). Филиалы выбираются только для ролей вне HR (у `superadmin`/`admin`/`hr_manager` — все филиалы).
Роль `admin` пока доступа не имеет. Все маршруты: `auth:sanctum` + `EnsureUserIsActive` + `can:manage-users`.
Гость → 401, другая роль → 403.

### Эндпоинты (`/api/users`)
| Метод и путь | Тело / параметры | Ответ |
|---|---|---|
| `GET /` | `q`, `status` (`active\|blocked`), `role`, `perPage` 1..100 (строка `"20"` тоже принимается), `page` | `{data: [...], links, meta}` |
| `POST /` | `{email, name, role: admin\|recruiter\|viewer}` | 201 `{data: user}`; e-mail занят → 409 `{code: "email_taken"}`; ошибки полей → 422 |
| `PATCH /{id}` | `{role?, status?, branch_ids?, safe_speak_handler?}` | 200 `{data: user}`; себя (роль/статус/филиалы) → 422 `self_change_forbidden`; последний активный суперадмин → 422 `last_superadmin`; флаг обработчика не админу → 422 `handler_requires_admin`; нет id → 404 |

Пользователь в ответе (`Http/Resources/UserResource`): `id, name, email, avatar_url, roles[], status, branches[{id, name, status}], locale,
safe_speak_handler, invited_by, last_login_at, created_at`. `DELETE` не реализован намеренно.

### Филиалы пользователя
`branch_ids` — **полная замена** филиалов (`[]` — снять все; поле не передано — без изменений). Каждый id — целое
(строка `"3"` тоже принимается), без повторов, существующий **активный** филиал (иначе 422), не больше 200.
Хранятся в `branch_user` (модуль [Directory](directory.md)); запись — `UserAdminRepository::syncBranches`.
Выключенный филиал остаётся в `branches[]` пользователя со статусом `disabled`, но доступа не даёт и при следующем
сохранении из интерфейса снимается. Как филиалы ограничивают данные — `AccessibleBranches` в [directory.md](directory.md).

### Правила (`Services\UserAdminService`)
- Приглашение: e-mail приводится к нижнему регистру, проверка занятости без учёта регистра, `invited_by` = кто пригласил.
- Аудит: `users.invited` / `users.updated` в лог — только id и роль/статус, без e-mail и имён.
- Проверка «последний активный суперадмин» и само изменение выполняются в одной транзакции с блокировкой строк
  суперадминов (`Repositories\EloquentUserAdminRepository::transaction`), чтобы два одновременных запроса не
  оставили систему без суперадмина.

### Обработчик Safe Speak
Флаг можно дать HR (`superadmin`, `admin`, `hr_manager` — `UserRole::hrStaff()`); другим ролям — 422 `handler_requires_admin`.
Колонка `users.safe_speak_handler boolean default false` — миграция модуля Users
`Database/Migrations/2026_10_05_100001_add_safe_speak_handler_to_users.php`. Явный флаг, а не новая роль: читать
анонимные жалобы должны не все админы. Сервис: `true` только для суперадмина/админа (с учётом роли, меняемой тем же
запросом); смена роли на не-админскую снимает флаг в той же транзакции. Проверка доступа — gate `safe-speak-handle`
модуля SafeSpeak.

### Слои
`Http/Controllers/UsersController` → `Http/Requests` (`ListUsersRequest`, `InviteUserRequest`, `UpdateUserRequest`) →
`Services/UserAdminService` → `Contracts/UserAdminRepository` (`Repositories/EloquentUserAdminRepository`).
Бизнес-ошибки — `Exceptions/UserAdminException` (сам отдаёт JSON `{message, code}` с нужным статусом).
Роли и статусы — enum модуля Auth.

### Фронтенд (`features/users`)
`users.page.ts` — таблица (Angular Material), состояния «загрузка / пусто / ошибка с повтором», оптимистичные
изменения с откатом; `invite-user.dialog.ts` — форма приглашения; колонка «Філії» берёт список активных филиалов через
`features/directory/directory.service.ts` (`active('branches')`); `users.service.ts` — HTTP и перевод кодов ошибок
в i18n-ключи. Маршрут `/admin/users` защищён `roleGuard('superadmin')`.

## Как проверить
Тесты: `tests/Feature/Users/UsersAdminTest.php` (401/403, пагинация и `perPage` строкой, фильтры, приглашение,
422/409, смена роли/статуса, запрет менять себя, 404, назначение/замена/снятие филиалов, валидация `branch_ids`), `tests/Unit/Users/UserAdminServiceTest.php`
(правило последнего суперадмина, филиалы только когда переданы), `frontend/.../users.service.spec.ts`.

Вручную (нужна сессия суперадмина в браузере): DevTools → Network, или
```bash
curl -i "https://sinhrm.vercel.app/api/users?perPage=20"   # без сессии → 401
```

## Роль суперадмина
Суперадмин **не назначается** ни через API, ни из интерфейса: `PATCH /api/users/{id}` принимает только `admin`,
`recruiter`, `viewer` (иначе 422). Суперадмином становится только владелец адреса из `SUPERADMIN_EMAIL` при первом входе.
В таблице у суперадмина вместо выбора роли — неизменяемая метка.

## Доступ к модулю

Ключ модуля `users`. Это **базовый** модуль: его нельзя выключить или ограничить по ролям на странице «Адміністрування → Модулі». Подробнее — [modules-access.md](modules-access.md).
