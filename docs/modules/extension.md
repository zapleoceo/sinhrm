# Модуль Extension (браузерное расширение «SinHRM Clipper»)

## Что это и зачем
Рекрутер смотрит профиль кандидата на LinkedIn, Work.ua, Djinni или DOU и хочет добавить его в SinHRM, не перепечатывая
данные. Он нажимает кнопку расширения на панели Chrome — открывается окошко с тем, что расширение нашло **на этой
странице** (имя, должность/заголовок, город, видимые контакты, ссылка на профиль, текст «о себе» до 2000 символов).
Можно поправить поля, выбрать вакансию (необязательно) и нажать «Додати в SinHRM». Если такой кандидат уже есть
(та же ссылка на профиль, телефон, e-mail или Telegram) — расширение покажет ссылку на существующую карточку, а не создаст
дубль. В карточке появляется заметка «Imported from <сайт>» со ссылкой на профиль.

**Что расширение не делает — намеренно:** не открывает страницы само, не обходит списки и поиск, не работает в фоне,
не читает ничего до нажатия кнопки. Читается только одна страница, открытая сейчас, и только по клику
(разрешение Chrome `activeTab`).

## Как пользоваться
### Установка (пока без Chrome Web Store)
1. Получите архив `sinhrm-clipper.zip`: артефакт `sinhrm-clipper` в запуске CI (GitHub → Actions → CI → нужный запуск →
   Artifacts) или соберите сами: `cd extension && npm ci && npm run package` → `extension/dist/sinhrm-clipper.zip`.
2. Распакуйте архив в постоянную папку (Chrome загружает расширение из неё; удалите папку — пропадёт расширение).
3. Откройте `chrome://extensions` → включите **Developer mode** (Режим разработчика) справа вверху.
4. **Load unpacked** (Загрузить распакованное) → выберите распакованную папку (в ней лежит `manifest.json`).
5. Закрепите значок «SinHRM Clipper» на панели (значок пазла → булавка).

### Подключение к SinHRM
1. В SinHRM: меню пользователя (аватар справа вверху) → «Розширення браузера» (`/settings/extension`) → «Створити токен».
2. Токен показывается **один раз** — нажмите «Копіювати».
3. В расширении: правый клик по значку → «Параметры» (Options). Адрес API — `https://sinhrm.vercel.app` (по умолчанию),
   вставьте токен → «Зберегти» → «Перевірити зʼєднання» (должно показать ваше имя).
4. Токен действует 90 дней. Новый токен автоматически отзывает старый; «Відкликати» — отключить расширение сразу.

### Добавление кандидата
Откройте профиль → нажмите значок → проверьте поля → вакансия (необязательно) → кнопка добавления.
Результат: «Кандидата створено» / «Вже є в SinHRM» со ссылкой на карточку. Ошибки: токен недействителен или истёк →
откройте настройки; кандидат есть в другом филиале (не видно вам) → сообщение без подробностей; нет доступа к вакансии;
слишком много запросов (больше 30 в минуту) — подождите минуту.

### Поддерживаемые страницы
| Сайт | Адрес профиля |
|---|---|
| LinkedIn | `https://www.linkedin.com/in/<slug>/` |
| Work.ua | `https://www.work.ua/resumes/<id>/` (также `/ru/…`, `/en/…`) |
| Djinni | `https://djinni.co/q/<id>/…` |
| DOU | `https://dou.ua/users/<slug>/` |

На остальных страницах окошко сообщает, что страница не поддерживается, и ничего не читает.

## Приватность и условия сайтов (важно)
- **LinkedIn запрещает автоматический сбор данных** (User Agreement, раздел про scraping/automation) и активно блокирует
  аккаунты за него. Расширение сделано так, чтобы оставаться ручным инструментом: одна открытая страница, явный клик,
  никаких фоновых запросов, обхода, открытия вкладок. Тем не менее риск остаётся на стороне пользователя: решение
  использовать расширение на LinkedIn — за владельцем процесса. Work.ua, Djinni и DOU также ограничивают массовый сбор.
- Расширение передаёт в SinHRM только то, что человек видит на странице и подтверждает кнопкой. Персональные данные
  кандидата обрабатываются по правилам [secrets.md](../architecture/secrets.md) (Закон Украины №2297-VI).
- Токен хранится в `chrome.storage.local` этого браузера. Он даёт доступ **только** к `/api/clipper/*`
  (см. «Как устроено»), не к остальному API.

## Ограничения
- **Селекторы ломаются, когда сайты меняют вёрстку.** Порядок извлечения: JSON-LD (`Person`) → мета-теги `og:` →
  DOM-селекторы. Селекторы написаны по публичным описаниям разметки и проверены только на **вымышленных** фикстурах
  (`extension/tests/fixtures`) — на реальных страницах не калибровались. Если поле не нашлось, его можно вписать руками.
- Контакты находятся только если они видны на странице (ссылки `mailto:`, `tel:`, `t.me/…`).
- Только Chrome/Chromium (Manifest V3). В Chrome Web Store не опубликовано.
- Адрес API, отличный от `sinhrm.vercel.app`, требует разрешения хоста, которого в манифесте нет — такие запросы
  пройдут только через CORS `/api/clipper/*`.

## Как устроено
### Расширение (`extension/`)
TypeScript без фреймворка, сборка esbuild, тесты Vitest + jsdom, линт ESLint (typescript-eslint).
| Файл | Что |
|---|---|
| `static/manifest.json` | MV3: `permissions: activeTab, scripting, storage`; `host_permissions` — только четыре сайта и `https://sinhrm.vercel.app/*`; без content scripts и service worker |
| `src/popup.ts`, `static/popup.html` | окошко: определение сайта по адресу вкладки, извлечение, форма, отправка |
| `src/detect.ts` | адрес вкладки → `linkedin \| work_ua \| djinni \| dou` или «не поддерживается» |
| `src/extract.ts` | точка входа внедряемого скрипта: собирается в один самодостаточный `extract.js`, последняя строка — вызов `run()`; popup вызывает `chrome.scripting.executeScript({files: ['extract.js']})` и получает результат последнего выражения (вариант с `func:` не подходит — функция сериализуется без импортов) |
| `src/extractors/{common,linkedin,workua,djinni,dou}.ts` | извлечение: JSON-LD → `og:` → DOM; `profile_url` — `<link rel=canonical>` → `og:url` → адрес вкладки, без query/hash; `summary` ≤ 2000 символов |
| `src/api.ts` | `GET /api/clipper/me`, `POST /api/clipper/candidates`, `Authorization: Bearer`, `credentials: 'omit'` |
| `src/settings.ts`, `src/options.ts`, `static/options.html` | адрес API (только https, по умолчанию `https://sinhrm.vercel.app`) и токен в `chrome.storage.local`, проверка соединения |
| `src/i18n/{uk,ru,en}.json` | строки интерфейса (язык браузера; uk/ru, иначе en) |
| `scripts/build.mjs`, `scripts/package.mjs` | сборка в `extension/build/`, архив `extension/dist/sinhrm-clipper.zip` (`build/` и `dist/` в `.gitignore`) |

Команды: `npm run lint`, `npm run typecheck`, `npm test`, `npm run build`, `npm run package`. В CI — job `extension`
(`.github/workflows/ci.yml`), архив сохраняется артефактом `sinhrm-clipper` на 30 дней.

### API (модуль Recruiting)
| Метод и путь | Аутентификация | Ответ |
|---|---|---|
| `GET /api/me/extension-token` | сессия | `{active, created_at, last_used_at, expires_at}` |
| `POST /api/me/extension-token` | сессия | 201 + `token` (открытый текст, один раз); предыдущий токен удаляется |
| `DELETE /api/me/extension-token` | сессия | 204 |
| `GET /api/clipper/me` | токен `clipper` | `{user: {id, name, email}, vacancies: [{id, title, branch}]}` — открытые вакансии в области видимости |
| `POST /api/clipper/candidates` | токен `clipper`, роль с правом записи | `{full_name, source_site (linkedin\|work_ua\|djinni\|dou), profile_url, headline?, location?, phone?, email?, telegram?, summary?, vacancy_id?}` → 201 `{candidate_id, url, created: true}` / 200 `{…, created: false}`; 409 `duplicate_candidate {restricted: true}`; 403 `vacancy_out_of_scope`; 422 (в т.ч. `profile_url` не https или не на хосте сайта); 429 |

Подробности токенов — [auth.md](auth.md#токены-браузерного-расширения), дедупликации и заметки — [recruiting.md](recruiting.md).

## Как проверить
- Расширение: `cd extension && npm ci && npm run lint && npm run typecheck && npm test && npm run package`
  (тесты: определение сайта, извлечение на вымышленных страницах всех четырёх сайтов — JSON-LD, `og:`, DOM, обрезка
  до 2000, Telegram; API-клиент с подменённым `fetch`; одинаковые ключи в трёх словарях).
- Бэкенд: `tests/Feature/Recruiting/ExtensionApiTest.php`, `tests/Unit/Recruiting/ClipperSiteTest.php`.
- Фронт: `frontend/src/app/features/extension/extension.spec.ts`.
- Вручную: установить по шагам выше, открыть свой профиль на любом из сайтов, нажать значок.

**Не проверено:** расширение не загружалось в реальный Chrome, извлечение не проверялось на реальных страницах сайтов,
запросы к preview/prod API не выполнялись.
