# Модуль Audit (журнал действий)

## Что это и зачем
Журнал отвечает на вопрос «кто, что и когда поменял». Каждая важная правка — отдельная строка: кто её сделал,
с какой записью (сотрудник, кандидат, заявка…), что именно сделал (создал, изменил, сменил статус, перевёл по
воронке, сменил роль, задал ключ интеграции…) и когда. Для изменений видно «было → стало» по каждому полю.

Личное и секретное в журнал **не попадает**:
- зарплаты, телефоны, e-mail, адреса, дата рождения, заметки и причины, ключи и пароли — видно только сам факт
  изменения, вместо значения стоит `***`;
- значение ключа интеграции не пишется никогда — только «ключ `bot_token` задан / удалён»;
- анонимные модули (**Safe Speak** и **Pulse**: опросы и настроение) не пишутся вообще, даже как «что-то изменилось».
  Это защищено в коде: такую модель нельзя подключить к журналу — приложение не запустится.

Записи старше **одного года** удаляются автоматически.

## Как пользоваться
- **Адміністрування → Журнал дій** (только суперадмин). Фильтры: пользователь, тип записи, действие, период
  «с — по». Список постраничный, новые сверху. По ссылке в строке — переход к самой записи (профиль сотрудника,
  карточка кандидата, вакансия, пользователи, интеграции).
- **Вкладка «Історія» в профиле сотрудника** — видна только HR (суперадмин, админ, HR-менеджер). Коллеги видят
  профиль, но не историю его изменений.
- **Раздел «Історія» в карточке кандидата** — виден всем, кто может открыть эту карточку. Там же переводы по воронке
  и отказы по всем его откликам.

## Как устроено
### Что пишется
| Запись (`entity_type`) | Модель | Особые действия |
|---|---|---|
| `user` | `App\Models\User` | `status_changed` (активация/блокировка), `role_changed` (приглашение и смена роли, из `Users\Services\UserAdminService`) |
| `integration` | `Integrations\Models\Integration` | `secret_set` / `secret_cleared` с `meta.secret` = имя ключа (из `IntegrationSecret`) |
| `ai_prompt_version` | `Ai\Models\AiPromptVersion` | `prompt_activated`; текст промпта не пишется (`body` маскируется) |
| `employee` | `People\Models\Employee` | `status_changed` (в т.ч. увольнение) |
| `vacancy`, `candidate`, `application` | `Recruiting\Models\*` | `stage_changed` у отклика, `meta.candidate_id` |
| `leave_request` | `TimeOff\Models\LeaveRequest` | `status_changed` = решение по отсутствию |
| `document` | `Documents\Models\Document` | `status_changed` |
| `hiring_request`, `hiring_approval` | `HiringRequests\Models\*` | `status_changed` = решение согласующего |
| `workflow_template` | `Workflows\Models\WorkflowTemplate` | — |

Список — `Providers\AuditServiceProvider::TRACKED`: новая модель = одна строка. Запись делает общий наблюдатель
Eloquent (`Support\AuditObserver`) на `created / updated / deleted`. Действие уточняется по изменённым полям:
`stage_id` → `stage_changed`, `is_active=true` → `prompt_activated`, `status` → `status_changed`, иначе `updated`.
Массовые `query()->update()` наблюдатель не видит (это системная работа: пометки «уведомлён», пропуск шагов).
Поэтому удаление ключа интеграции переведено на удаление по модели (`EloquentSecretVault::forget`).

Кто сделал — текущий пользователь запроса; у cron и очередей — пусто («система»).

### Маскирование и исключения — `Support\AuditPolicy`
- Маска `***` для полей, в имени которых есть: `salary, compensation, password, secret, token, value, phone, email,
  telegram, address, emergency, birth, personal, custom_fields, note, reason, comment, body, utm, avatar, google_id`.
  Если поле было/стало пустым, пишется `null` (видно «задали» / «очистили»).
- Не пишутся: `id`, `created_at`, `updated_at`, `remember_token`, `last_login_at`, `last_touch_at`, `stage_entered_at`,
  `last_checked_at`, `last_error`, `notified`, `escalated`. Правка только этих полей строку не создаёт.
- Модели из `App\Modules\SafeSpeak\` и `App\Modules\Pulse\` и типы `safe_speak*`, `pulse*`, `survey*`, `mood*`
  отклоняются с `LogicException`.
- Длинные строки обрезаются до 500 символов.

Вручную из другого модуля: `Contracts\AuditLogger::record($entityType, $entityId, AuditAction, $changes, $meta, $actorId)`.
Значения маскируются там же, `meta` — только неличные данные.

### Таблица `audit_log`
`id, user_id (без FK — история переживает удаление пользователя), entity_type, entity_id, action, changes jsonb
{поле: {from, to}}, meta jsonb, created_at`. Индексы: `(entity_type, entity_id, id)`, `(user_id, id)`, `(action, id)`,
`created_at`. Строки только добавляются; удаляет их только задача хранения.

### Хранение
Задача `audit.retention` (`Services\AuditRetentionJob`, cron через `POST /api/ops/jobs/run`) удаляет строки старше
365 дней. Повторный запуск ничего не делает.

### Эндпоинты
| Метод и путь | Доступ | Параметры | Ответ |
|---|---|---|---|
| `GET /api/audit` | суперадмин (`can:view-audit-log`) | `user_id`, `entity_type`, `action`, `from`, `to` (`YYYY-MM-DD`, включительно), `page`, `perPage` 1..100 | `{data: [entry], links, meta}` |
| `GET /api/audit/options` | суперадмин | — | `{entity_types[], actions[], users[{id, name}]}` |
| `GET /api/people/{id}/history` | `can:people-manage` (HR) | `page`, `perPage` | как выше |
| `GET /api/candidates/{id}/history` | политика `view` кандидата | `page`, `perPage` | кандидат + все его отклики |

`entry`: `id, action, entity_type, entity_id, user {id, name} | null, changes, meta, created_at`.
Гость → 401, нет прав → 403, неверный фильтр → 422.

### Фронтенд
`features/audit`: страница `/admin/audit` (`roleGuard('superadmin')`), сервис, модель, `audit.format.ts` (ссылка
на запись и строки «поле: было → стало»), компонент `audit-history.ts` для вкладок профиля и карточки кандидата.

### Выбор решения
| Кандидат | Вердикт | Почему |
|---|---|---|
| spatie/laravel-activitylog 5.1 (MIT, релиз 2026-09, Laravel 12/13) | нет | трейт в каждую модель и настройки по моделям — правила маскирования и запрет анонимных модулей разъехались бы по модулям; свои таблица и миграция вне модульной схемы |
| owen-it/laravel-auditing 14 (MIT, релиз 2026-06, Laravel 11–13) | нет | тот же подход «интерфейс + трейт в модели», пишет IP/user-agent и полные значения по умолчанию — лишние личные данные |
| своё (этот модуль) | да | одна таблица, один наблюдатель и одна политика; не требует правок моделей других модулей |

## Как проверить
- `php artisan test tests/Feature/Audit tests/Unit/Audit`: запись и автор, маскирование, ключ без значения,
  смена роли, перевод по воронке, доступ к журналу и вкладкам, исключение анонимных модулей, хранение.
- Вручную: поменяйте роль пользователю → «Журнал дій» → строка «Зміна ролі» с «было → стало».
