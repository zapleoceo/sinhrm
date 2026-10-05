# PROD-01 — Дати журналу інтеграцій

## План
1. Продовжити збережений draft тільки у `fix/integration-log-locale`: мова uk/ru/en визначає формат, часовий пояс браузера визначає час.
2. Захистити обидві дати (остання перевірка, журнал) від null, порожніх і некоректних значень; API та fixtures зберегти.
3. Точкові unit-тести відображення й перемикання мови; синтетичні e2e для UTC/Kyiv/Los Angeles, зими/літа й опівночі у CI.
4. Оновити модуль і worklog, lint/точкові unit локально; commit/push, draft PR. Повні перевірки тільки CI.

## Контекст з KB
- 2026-10-05: Atlassian Rovo, запит `SinHRM integration log timestamp locale timezone`, site itstep: HTTP 502, пошук недоступний. Початковий limit=3 відхилений конектором, повторено з limit=10.
- Пам'ять перевірена за integration/locale/SinHRM: релевантних матеріалів про цю правку немає.
- Джерела у repo: `docs/modules/integrations.md`, `LanguageService`, `DATE_LOCALES`, існуючий синтетичний e2e harness. Draft належить цій задачі: тільки integrations locale/test/doc файли, база `3b4ac30`.

## Стан
- Час: 2026-10-05, Asia/Saigon.
- Крок: реалізація готова до CI та незалежного рев'ю.
- Виконано: безпечний pure pipe для null/порожніх/некоректних дат, locale signal і browser timezone, unit відображення/перемикання/offset/epoch, синтетичні e2e 3 мови × 3 часові пояси × 4 viewport/theme projects; fixtures збережено. Модуль і worklog оновлено.
- Доказ: targeted Angular і e2e ESLint пройшли; локальний unit build зупинився на відсутніх 4 FontAwesome пакетах у спільному node_modules, e2e tsc — відсутній @types/node. Залежності локально не встановлювалися. Unit/e2e результат очікується з CI.
- Доставка: draft PR [#146](https://github.com/zapleoceo/sinhrm/pull/146), attached до Codex. Код SHA `c8e78f895e73aa5e1e40280797a5b2601ec75394`.
- CI цього коду: [run 37272582125](https://github.com/zapleoceo/sinhrm/actions/runs/37272582125): frontend lint/unit/coverage/build зелені, 10 нових тестів дат пройшли; загалом 145 files, 978 passed / 1 skipped. Docs/worklog/security/extension/API-docs/backend-lint зелені; backend tests та ui-parity на момент handoff виконуються. Скріншоти ще не підтверджені.
- Наступна дія: root перевіряє CI остаточного PR head та screenshots, організовує незалежне Luna/Astra рев'ю; PR залишається draft, без merge/deploy. Цей запис оновлює тільки стан після перевірки коду.
- CI correction: Chromium ICU повертає `Europe/Kiev` як canonical alias налаштованого `Europe/Kyiv` (12 browser cases впали на exact-name gate). Gate тепер приймає лише ці два імені для Kyiv та додатково перевіряє фактичні UTC offsets кожного winter/summer/midnight instant; date assertions збережені. Root перевіряє новий точний SHA CI.
- Обмеження: локальний app/server і повні suite заборонені; без merge, preview label/deploy та live OAuth. SOL/Luna/Astra дозволені власником замість Opus/Sonnet.
