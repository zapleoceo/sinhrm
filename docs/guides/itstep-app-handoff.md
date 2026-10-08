# Хенд-офф приложения для размещения в инфраструктуре Itstep

Документ описывает требования самого приложения и артефакты сборки. Он не задаёт конфигурацию серверов, резервное копирование, RPO, мониторинг инфраструктуры или сетевые правила.

## Что проверено в репозитории

- Backend — Laravel 13 (`backend/composer.json`), Composer-зависимости зафиксированы в `backend/composer.lock`. Минимальная версия PHP по Composer — 8.3; CI устанавливает PHP 8.4 и расширения `pdo_pgsql`, `pgsql`, `intl`, `sodium`. Для целевой среды рекомендуется PHP 8.4 с этими расширениями; окончательную проверку требований зафиксированного набора пакетов выполнять командой `composer check-platform-reqs --no-dev`.
- HTTP entrypoint — `backend/public/index.php`. Для обычного PHP-FPM/Apache/Nginx document root должен указывать на `backend/public`, а все неизвестные пути API передаваться через этот front controller. `backend/api/index.php` и `backend/vercel.json` — Vercel-адаптеры; запуск приложения от корня репозитория или через этот адаптер на Itstep не требуется. При запуске непосредственно через `public/index.php` PHP runtime должен задавать `zend.exception_ignore_args=1` (Vercel-адаптер задаёт это сам): проверить эффективное значение именно для PHP-FPM/web runtime, а не только CLI. Это не допускает попадания значений аргументов функций в exception traces.
- Frontend — Angular application builder. Использовать Node.js 24, `npm` версии из `frontend/package.json` (`npm@11.17.0`) и `frontend/package-lock.json`: `cd frontend && npm ci && npm run build`. Сборка запускает генерацию документации и создаёт статический сайт в `frontend/dist/frontend/browser` (стандартный browser output Angular application builder). Публиковать содержимое этой папки.
- Frontend отправляет запросы на относительные `/api/...` и `/sanctum/...`. В текущем Vercel-размещении эти пути направляются из `frontend/vercel.json` в отдельный API-проект. Для Itstep настройка фронтенда может остаться неизменной, если reverse proxy публикует SPA и API под одним внешним origin и направляет `/api/*` и `/sanctum/*` в Laravel, а остальные неизвестные UI-пути — на `index.html`. Это сохраняет cookie-аутентификацию Sanctum. Отдельные browser-origin для SPA и API потребуют отдельной доработки credentialed CORS/Sanctum и не являются проверенным конфигурационным сценарием приложения.
- API health check — `GET /api/health`; он проверяет подключение к базе и отвечает HTTP 503 при ошибке. Laravel также регистрирует `/up` для проверки запуска процесса; проверка базы доступна на `/api/health`.
- **Целевая база на инфраструктуре IT STEP — MySQL 8.4** (InnoDB, `utf8mb4`, collation `utf8mb4_0900_ai_ci`, строгий `sql_mode`, сессия в UTC — задано в `config/database.php`, [ADR 0010](../adr/0010-mysql-dual-support.md)): `DB_CONNECTION=mysql`, `DB_URL=mysql://<user>:<password>@<host>:3306/<db>`, при TLS — `MYSQL_ATTR_SSL_CA`. Код на переходный период поддерживает и PostgreSQL (текущий прод на Neon); MySQL-путь проверяет CI job `tests-mysql`. Перенос данных и `mysqldump`-бэкап — отдельные этапы (PROD-47, PROD-48).
- Текущий прод — PostgreSQL. `DB_URL` имеет приоритет, конфигурация также принимает `DATABASE_URL`. Таблицы сессий, кэша, очереди и приложения создаются миграциями. Перед первым запуском задаётся постоянный `APP_KEY`; при обновлениях его нельзя генерировать заново.
- В текущем коде модулей нет обработчиков Laravel `ShouldQueue`/`queue:work`. Прикладные фоновые действия реализованы через `Core\Contracts\ScheduledJob` и запускаются вызовом `POST /api/ops/jobs/run` с `X-Ops-Secret`; текущий GitHub Actions workflow делает это каждые 30 минут. Для другого планировщика нужно перенести этот интервал и хранить секрет в защищённом окружении планировщика. Не запускайте отдельный `queue:work` как требование приложения без появления соответствующих queued jobs.
- Прикреплённые документы до 2 МиБ хранятся в таблице `documents_files` выбранной БД (PostgreSQL сейчас, MySQL 8.4 на IT STEP), а не на локальном диске. Локальные каталоги Laravel всё равно должны быть доступны для записи процессу PHP (в частности, `storage/framework` и `bootstrap/cache`); логи для окружения приложения направлять в stderr или в инфраструктурный сборщик.

## Переменные приложения

Переменные ниже — имена из текущей конфигурации, без значений и секретов. Настроить их в среде приложения, а не добавлять реальные значения в Git.

| Имя | Требование | Назначение |
|---|---|---|
| `APP_KEY` | обязательно | Постоянный ключ Laravel для шифрования и подписей. Создать один раз при первичной настройке и хранить стабильным при релизах. |
| `APP_ENV` | production | Включает производственный режим. |
| `APP_DEBUG` | `false` | Не раскрывать диагностические данные в HTTP-ответах. |
| `APP_URL` | обязательно | Внешний HTTPS URL приложения/API, используемый Laravel для ссылок. |
| `FRONTEND_URL` | обязательно для ссылок возврата из OAuth | Публичный origin SPA; сейчас в конфигурации есть Vercel default, поэтому для Itstep явно переопределить. |
| `DB_CONNECTION` | обязательно | `mysql` для IT STEP (MySQL 8.4); `pgsql` — текущий прод на Neon. |
| `DB_URL` или `DATABASE_URL` | обязательно | Connection URL выбранной СУБД: `mysql://user:pass@host:3306/db` для IT STEP (PostgreSQL — `postgresql://…`); предпочтительно `DB_URL`, поскольку он имеет приоритет. |
| `MYSQL_ATTR_SSL_CA` | если MySQL требует TLS | Путь к CA-сертификату сервера MySQL. |
| `SUPERADMIN_EMAIL` | обязательно | Адрес начального суперадминистратора. |
| `OPS_SECRET` | обязательно для миграций через ops endpoint и планового запуска модульных задач | Секрет заголовка `X-Ops-Secret`; не передавать как аргумент публичной команды и не логировать. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | обязательно для текущего входа | Текущая аутентификация пользователей использует Google OAuth; secret — только в защищённом хранилище окружения. |
| `GOOGLE_REDIRECT_URI` | обязательно для текущего входа | Зарегистрировать в Google OAuth полный Itstep callback URL `/api/auth/google/callback`; переопределить Vercel default из `backend/config/services.php`. `SUPERADMIN_EMAIL` входит первым через Google при подтверждённом совпадении email. |
| `GOOGLE_CONNECT_REDIRECT_URI` | только если включается Google Workspace | Отдельный callback URL `/api/google/connect/callback` для согласия на доступ Gmail/Calendar/Sheets; это не callback входа. |
| `SANCTUM_STATEFUL_DOMAINS` | настроить под внешний host | Домены SPA, которым разрешена stateful cookie-аутентификация. |
| `SESSION_SECURE_COOKIE` | `true` под HTTPS | Браузер передаёт сессию только по HTTPS. |
| `SESSION_DOMAIN` | при необходимости общего cookie-домена | Оставить конфигурацию по умолчанию при одном host; при отдельных поддоменах проверить cookie-domain совместно с архитектурой маршрутизации. |
| `TRUSTED_PROXIES` | только если перед приложением есть proxy | Указать лишь фактические адреса/CIDR proxy, которые переписывают forwarded headers. Не использовать `*`; см. `backend/config/trustedproxy.php`. |

Сессии, кэш и Laravel queue connection сейчас по умолчанию используют базу данных (PostgreSQL сейчас, MySQL 8.4 на IT STEP) (`SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION`); таблицы включены в миграции. Отдельные Redis или постоянные дисковые хранилища приложению для текущих функций не нужны. Если меняется очередь/кэш/сессии, перепроверить таблицы и эксплуатационный запуск по обновлённой конфигурации.

## Порядок передачи релиза

1. Собрать backend из `backend/`: установить зафиксированные production-зависимости через `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader`, затем проверить платформу Composer.
2. Собрать frontend из `frontend/` командами выше и публиковать только `dist/frontend/browser` как SPA.
3. Настроить один внешний HTTPS origin: проксировать `/api/*` и `/sanctum/*` к backend, остальные пути SPA отдавать через `index.html`. Ограничения и security headers из `frontend/vercel.json` сейчас применяет Vercel; при размещении вне Vercel их перенос на edge/web server является настройкой хостинга и должен быть выполнен там.
4. Задать перечисленные production-переменные и MySQL 8.4-соединение (`DB_CONNECTION=mysql`, `DB_URL`), включая Google login credentials и зарегистрированный callback для текущего входа. Убедиться, что PHP-FPM применяет `zend.exception_ignore_args=1`, а Laravel runtime может писать необходимые служебные каталоги. Если для Itstep требуется иной корпоративный способ входа, это требует отдельного согласования и изменения приложения.
5. До переключения трафика применить миграции командой `php artisan migrate --force` из `backend/` в окружении релиза. Не запускать preview/reset миграционный режим на рабочей базе.
6. Настроить запланированный POST `https://<внешний-origin>/api/ops/jobs/run` каждые 30 минут с заголовком `X-Ops-Secret`; проверять HTTP-статус и поле `ok` ответа, а в журнал планировщика писать только результат/счётчики без содержимого персональных данных.
7. Проверить `GET /api/health` после миграций, SPA по корневому URL и вход через Google с Itstep callback и cookie-сессией. Если настроен Google Workspace, отдельно проверить его callback подключения.

## Ограничения и зависимости релиза

- Автоматический релиз в этом репозитории сейчас настроен на Vercel workflow. Его не следует считать механизмом публикации в инфраструктуру Itstep; здесь описана сборка и контракт приложения, а pipeline размещения должен быть согласован отдельной DevOps-командой.
- В backend есть ограниченные Vercel-специфичные предположения в таймаутах отдельных операций и Neon-совместимости (`Core/Support/NeonConnectionConfig`); последнее применяется только к Neon URL и libpq старше, чем поддерживающий SNI. Для PostgreSQL Itstep вне Neon этот workaround не меняет URL. Перед эксплуатацией проверить прикладные таймауты на выбранном PHP runtime.
- В baseline `main` (`3b4ac30`) нет изменений из отдельной подготовленной ветки интеграции происхождения кандидатов PROD-145. Этот handoff их не включает и не реализует повторно; функциональность этой ветки остаётся отдельной зависимостью продуктового релиза.
- Перемещение production, создание серверов, резервное копирование, RPO, инфраструктурные alerts и доступы к хосту остаются вне этого app-side handoff.
