# HRM-14 — прозорість скла до 100%

## План

1. Розширити повзунок і нормалізацію локальної прозорості до `0..100`, не змінюючи типографіку чи вміст.
2. Перевірити межі, збереження та старі налаштування; оновити опис модуля і worklog.
3. Запустити лише цільові тести, lint і docs gates та передати точний diff на незалежний review.

## Контекст з KB

- За записом у `MEMORY.md` SinHRM має окремий frontend checkout; актуальний стан тут підтверджено Git у призначеному worktree. Пошук інших знань не потрібен для цієї вузької зміни.

## Стан

- Час: 2026-10-30 Asia/Saigon.
- Worktree: `D:/Projects/HRM/worktrees/gitlab-glass-range`, branch `feat/glass-transparency-100`, база `75db9708287283ac1efd09aa51e3c59ec740ef92`.
- Обсяг: налаштування зовнішнього вигляду і theme service, їх цільові тести, опис desktop-модуля та worklog.
- Виконано: повзунок і sanitizer приймають `0..100`; значення `100` дає `--os-glass-opacity: 0%`, `0` — `100%`. Значення `40` збережене без зміни сенсу; типове значення лишилось `18`. Стиль `solid` і fast mode як і раніше задають непрозору chrome surface окремими CSS-правилами; вміст не змінювався.
- Перевірки: targeted Angular tests — 12/12 pass; `ng lint` — pass; `npm run test:docs` — 9/9 pass; `node scripts/build-docs.mjs` — 35 сторінок, без помилок; `git diff --check` — pass.
- Обмеження: full build, e2e та rendered screenshot не запускались; потрібен візуальний review об'єднаного UI в координатора.
- Блокери: немає.
- Наступний крок: перевірити фінальний diff та передати цей заморожений worktree координатору для SOL review і інтегрованої UI-валідації.

### Follow-up — візуальна межа 100%

- Час: 2026-10-30 13:52 Asia/Saigon.
- Джерело реалізації вже зафіксовано координатором: `fa366bb66821cc29b78deebd55eb8bafcbe1e06c`.
- Додано крок у `e2e/os-performance.pw.ts`: прозорість 100%, CSS opacity `0%`, перевірка прозорих chrome headers та непрозорого вмісту через спільну `captureMaterials`, зі скриншотом як Playwright attachment. Існуючий case 40% збережено.
- `npm run e2e:lint` — pass (7/7; збережене попередження Node про module type у `e2e/inventory.ts`); `git diff --check` — pass. Runtime e2e/build не запускались за обсягом follow-up, тож новий скриншот ще має бути отриманий у CI або погодженому візуальному прогоні.
- Наступний крок: передати координатору на SOL review; не комітити цей follow-up окремо.
