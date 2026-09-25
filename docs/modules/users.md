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
- Свою роль и статус менять нельзя; последнего активного суперадмина нельзя понизить или заблокировать.

## Как устроено
### Доступ
Gate `manage-users` (`Providers\UsersServiceProvider::MANAGE_USERS`): активный пользователь с ролью `superadmin`.
Роль `admin` пока доступа не имеет. Все маршруты: `auth:sanctum` + `EnsureUserIsActive` + `can:manage-users`.
Гость → 401, другая роль → 403.

### Эндпоинты (`/api/users`)
| Метод и путь | Тело / параметры | Ответ |
|---|---|---|
| `GET /` | `q`, `status` (`active\|blocked`), `role`, `perPage` 1..100 (строка `"20"` тоже принимается), `page` | `{data: [...], links, meta}` |
| `POST /` | `{email, name, role: admin\|recruiter\|viewer}` | 201 `{data: user}`; e-mail занят → 409 `{code: "email_taken"}`; ошибки полей → 422 |
| `PATCH /{id}` | `{role?, status?}` | 200 `{data: user}`; себя → 422 `self_change_forbidden`; последний активный суперадмин → 422 `last_superadmin`; нет id → 404 |

Пользователь в ответе (`Http/Resources/UserResource`): `id, name, email, avatar_url, roles[], status, locale,
invited_by, last_login_at, created_at`. `DELETE` не реализован намеренно.

### Правила (`Services\UserAdminService`)
- Приглашение: e-mail приводится к нижнему регистру, проверка занятости без учёта регистра, `invited_by` = кто пригласил.
- Аудит: `users.invited` / `users.updated` в лог — только id и роль/статус, без e-mail и имён.
- Проверка «последний активный суперадмин» и само изменение выполняются в одной транзакции с блокировкой строк
  суперадминов (`Repositories\EloquentUserAdminRepository::transaction`), чтобы два одновременных запроса не
  оставили систему без суперадмина.

### Слои
`Http/Controllers/UsersController` → `Http/Requests` (`ListUsersRequest`, `InviteUserRequest`, `UpdateUserRequest`) →
`Services/UserAdminService` → `Contracts/UserAdminRepository` (`Repositories/EloquentUserAdminRepository`).
Бизнес-ошибки — `Exceptions/UserAdminException` (сам отдаёт JSON `{message, code}` с нужным статусом).
Роли и статусы — enum модуля Auth.

### Фронтенд (`features/users`)
`users.page.ts` — таблица (Angular Material), состояния «загрузка / пусто / ошибка с повтором», оптимистичные
изменения с откатом; `invite-user.dialog.ts` — форма приглашения; `users.service.ts` — HTTP и перевод кодов ошибок
в i18n-ключи. Маршрут `/admin/users` защищён `roleGuard('superadmin')`.

## Как проверить
Тесты: `tests/Feature/Users/UsersAdminTest.php` (401/403, пагинация и `perPage` строкой, фильтры, приглашение,
422/409, смена роли/статуса, запрет менять себя, 404), `tests/Unit/Users/UserAdminServiceTest.php`
(правило последнего суперадмина), `frontend/.../users.service.spec.ts`.

Вручную (нужна сессия суперадмина в браузере): DevTools → Network, или
```bash
curl -i "https://sinhrm.vercel.app/api/users?perPage=20"   # без сессии → 401
```
