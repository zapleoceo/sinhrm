# Журнал ошибок и заголовки безопасности

## Простыми словами
Раньше ошибки на проде было не видно: Vercel хранит логи недолго, внешнего сервиса вроде Sentry нет (заводить
внешние аккаунты нельзя). Теперь приложение само складывает ошибки в свою базу, а суперадмин видит их в
«Адміністрування → Помилки»: что сломалось, где в коде, сколько раз и когда последний раз.
Одинаковые ошибки не плодят строки — у группы растёт счётчик. Личных данных в журнале нет.

Второе: браузеру и API добавлены **заголовки безопасности** — страницу нельзя встроить в чужой сайт (защита от
кликджекинга), браузер не выполняет скрипты с чужих доменов, соединение только по HTTPS.

## Журнал ошибок (`error_events`, модуль Observability)
**Что попадает.**
- `source=server` — необработанное исключение API: то, что Laravel «репортит» (500). Ответы 4xx (валидация, 401, 403,
  404) — не ошибки и не записываются.
- `source=web` — ошибка в браузере: исключение JS (глобальный `ErrorHandler` Angular, туда же приходят
  `window.onerror` и необработанные промисы) и ответ API 5xx, который увидел SPA (в том числе 502/504 от Vercel,
  до которых API не дошёл).

**Что хранится в строке (одна строка = одна группа).**
| Поле | Что |
|---|---|
| `fingerprint` | sha256 от `source + класс + файл + строка` — ключ группировки |
| `exception_class`, `file`, `line` | класс исключения и место в коде (путь от корня `backend/`); для web — имя ошибки и `chunk-XXXX.js:строка:столбец` |
| `message` | текст ошибки после очистки: `SecretScrubber` (токены, Bearer, секреты из vault), затем e-mail → `[email]`, длинные цифры (телефоны, номера документов) → `[number]`; до 1000 символов |
| `route` | имя маршрута API или путь SPA без query-строки |
| `last_user_id` | только id пользователя, последнего столкнувшегося с ошибкой |
| `count`, `first_seen_at`, `last_seen_at` | сколько раз, первый и последний раз |
| `resolved_at` | отметка «вирішено»; повтор ошибки снимает отметку (группа снова открыта) |

**Чего нет никогда:** тела запроса и ответа, заголовков, cookies, IP, стека целиком, имён и e-mail.

**Как пишется.** `bootstrap/app.php` регистрирует репортер первым: `ErrorRecorder::recordException()`. Запись — один
`INSERT … ON CONFLICT (fingerprint) DO UPDATE count = count + 1`. Регистратор **никогда не бросает исключений**:
если запись не удалась (база недоступна), в stderr уходит одна JSON-строка `error_log.record_failed` с классом
ошибки, и обычный лог Laravel работает как раньше. Ошибка внутри записи не записывается повторно (защита от петли).

**Браузер.** `core/errors/error-reporter.service.ts` отправляет `POST /api/errors/client` (`kind`, `message`,
`location`, `route`) только для вошедшего пользователя; одинаковая ошибка — не чаще раза в минуту, не больше 20 за
загрузку страницы; ошибки самой отправки не отправляются. Сервер ограничивает 10 запросов в минуту на пользователя
(429). 5xx ловит `core/errors/server-error.interceptor.ts`; путь нормализуется (`/api/people/42` → `/api/people/{id}`).

**API** (все, кроме `client`, — только суперадмин, gate `manage-integrations`):
| Метод | Путь | Что |
|---|---|---|
| POST | `/api/errors/client` | отчёт браузера, любой вошедший пользователь, 10/мин → 204 |
| GET | `/api/errors?status=open\|resolved\|all` | группы, новые сверху (до 200) |
| GET | `/api/errors/{id}` | одна группа |
| PATCH | `/api/errors/{id}` | `{"resolved": true\|false}` |

**Хранение 30 дней.** Задача cron `errors.prune` (через `POST /api/ops/jobs/run`, [ADR 0006](../adr/0006-cron-via-github-actions.md))
удаляет группы, которые не повторялись 30 дней.

**Экран.** «Адміністрування → Помилки» (`/admin/errors`, только суперадмин): фильтр «Відкриті / Вирішені / Усі»,
у группы — счётчик, источник, класс, текст, время; раскрытие показывает место в коде, маршрут, id пользователя,
первый/последний раз и переключатель «Вирішено».

## Заголовки безопасности
**API** (`Core\Http\Middleware\SecurityHeaders`, на всех ответах):
`Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'` (API отдаёт
JSON, ему ничего грузить не нужно), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
`Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` (камера, микрофон, геолокация, оплата, USB
выключены), `Strict-Transport-Security: max-age=31536000; includeSubDomains`. Исключение: `/api/docs` (Scramble UI,
только суперадмин) без CSP — он грузит свои скрипты; остальные заголовки есть.

**SPA** (`frontend/vercel.json`, `headers`, все пути кроме `/api/*` и `/sanctum/*`, которые проксируются в API) — те же
заголовки и CSP под то, что приложение реально грузит:
| Директива | Значение | Почему |
|---|---|---|
| `script-src` | `'self'` | только свои бандлы; inline-скриптов нет |
| `style-src` | `'self' 'unsafe-inline' https://fonts.googleapis.com` | Angular вставляет стили компонентов тегами `<style>`, сборка встраивает CSS шрифтов в `index.html` |
| `font-src` | `'self' https://fonts.gstatic.com` | Roboto и Material Symbols |
| `img-src` | `'self' data: https://*.googleusercontent.com` | аватар Google в меню |
| `manifest-src` | `'self'` | `site.webmanifest`; иконки (`favicon.svg`, `apple-touch-icon.png`) — свои файлы, покрыты `img-src 'self'` |
| `connect-src` | `'self'` | API на том же домене (rewrite Vercel) |
| `object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; upgrade-insecure-requests` | | |

Вход через Google — это переход по ссылке (навигация), CSP его не ограничивает.

**Почему `'unsafe-inline'` для стилей, а не nonce.** Nonce нужно генерировать на каждый ответ сервером, а SPA
отдаётся статикой с CDN Vercel — сервера для nonce нет. Инлайн-стили не исполняют код; главное — скрипты — закрыты
строго (`script-src 'self'`, без `unsafe-inline` и `unsafe-eval`).

**Почему выключен `inlineCritical`.** По умолчанию сборка Angular встраивает «критический» CSS и добавляет
маленький inline-скрипт, который подключает остальные стили; CSP `script-src 'self'` его блокирует. В
`angular.json` (production) `optimization.styles.inlineCritical: false` — стили грузятся обычной ссылкой, inline-скриптов
в `index.html` нет.

## Как проверить
Тесты: `tests/Feature/Observability/ErrorLogTest.php` (запись и очистка, группировка, повторное открытие, 4xx не
пишутся, отказ базы не ломает ответ, хранение 30 дней, лимит клиентского эндпоинта, доступ только суперадмину),
`tests/Feature/Core/SecurityHeadersTest.php`, `frontend/src/app/core/errors/error-reporter.spec.ts`.
Вручную: `curl -sI https://sinhrm.vercel.app/ | grep -i -E 'content-security|x-frame|strict-transport'` и то же для
`/api/health`.
