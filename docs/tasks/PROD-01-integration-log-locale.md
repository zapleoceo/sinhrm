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
- Наступна дія: commit/push, draft PR, перевірити CI точного SHA; без merge/deploy.
- Обмеження: локальний app/server і повні suite заборонені; без merge, preview label/deploy та live OAuth. SOL/Luna/Astra дозволені власником замість Opus/Sonnet.
