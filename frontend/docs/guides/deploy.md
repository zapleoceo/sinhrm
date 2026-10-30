# Деплой (фронтенд)

## Простыми словами
Фронтенд — статический сайт, который отдаёт маленький Node-сервер `server.mjs`. Фронт и бэкенд живут на одном домене: nginx направляет в бэкенд только `/api/*` и `/health?token=…`, всё остальное приходит сюда. Сервер ничего не проксирует, поэтому браузер видит один origin и cookie-сессия работает. Размещение — on-site Docker ITSTEP; Vercel не используется (`vercel.json` удалён).

## Как устроено
- **Сборка:** `npm ci && npm run build` → `dist/frontend/browser` (+ `public/help/docs.json` из `docs/`). Node берётся из `.nvmrc` (24).
- **Запуск:** `npm start` = `node server.mjs`. В проде сервер слушает **unix-сокет** `APP_SOCKET` (nginx делает `proxy_pass` на сокет; TCP-порты в проде не открываем). Без `APP_SOCKET` — TCP `PORT` (по умолчанию 4200), только для локальной проверки.
- **Что делает сервер:** статика с SPA-фолбэком на `index.html` для путей без расширения (отсутствующий файл с расширением — 404; метод кроме GET/HEAD — 405); заголовки безопасности (`server/headers.mjs`: CSP, HSTS, `X-Frame-Options`, Permissions-Policy с микрофоном для голосового ввода); путь бэкенда (`/api/*`, `/health`), дошедший до сервера, — JSON 404 (ошибка маршрутизации), а не `index.html`; `Cache-Control`: хешированные бандлы `immutable`, `index.html` `no-cache`, `/build.json` `no-store`.
- **Пост-деплой:** `run.sh` — ничего не мигрирует (БД у фронта нет) и сразу завершается с кодом 0. Старт приложения — отдельный шаг оркестратора.
- **CI/CD, Dockerfile, ветки окружений** — внешний репозиторий ci-cd. Здесь их нет.

## Маршрутизация на хосте
Staging — `https://sinhrm.itsrv.xyz`, прод — тот же принцип. Правило nginx: `/api/*` и `/health` → бэкенд, остальное → этот сервер. Всё, что нужно браузеру от бэкенда, лежит под `/api` (включая Sanctum: `/api/sanctum/csrf-cookie`); `/health` без токена закрыт, им пользуется только мониторинг (`/health?token=$HEALTH_TOKEN`).

## Переменные окружения
| Имя | Назначение |
|---|---|
| `APP_SOCKET` | путь unix-сокета (прод) |
| `PORT` | TCP-порт без сокета (локально), по умолчанию 4200 |
| `NODE_ENV` | `production` / `test` — оба отдают готовую сборку |

Секретов во фронтенде нет и быть не должно: всё, что попало в сборку, видит браузер.

## Связка с бэкенд-настройками
Бэкенд должен знать публичный адрес SPA (`FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_SECURE_COOKIE=true`) и OAuth-callback на этом же origin (`/api/auth/google/callback`); nginx — доверенный прокси (`TRUSTED_PROXIES`). Подробности — [хенд-офф приложения](https://gitlabs.itsrv.xyz/dev/sinhrm.api/-/blob/master/docs/guides/itstep-app-handoff.md).

## Проверка после выкладки
1. `GET /` отдаёт приложение, `GET /recruiting/board` (клиентский маршрут) — тоже 200.
2. `GET /api/health` через тот же origin → 200 от бэкенда (nginx), `GET /api/sanctum/csrf-cookie` → 204 с cookie `XSRF-TOKEN`.
3. Заголовки безопасности присутствуют на HTML (`curl -I`).
4. Вход через Google и cookie-сессия работают.
