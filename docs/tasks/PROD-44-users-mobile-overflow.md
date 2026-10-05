# PROD-44: Users mobile page overflow

## Стан
- Етап: перевірити виправлення локально; зафіксувати наступний функціональний diff та перезапустити CI.
- База: `main` `38d90eaada1c43363cd64c2f16d7ceaecb611dab`; гілка `fix/users-mobile-overflow`.
- Підтверджено CI run `37289104847`, head `35658e8850bb3814b5e1b9f9498fcb9a1ac6975d` (усі 10 job успішні): viewport/client width `390 px`, document scroll width `1008 px`; таблиця має rect `x=13..1148 px`, ширину `1135 px`.
- Підтверджено CI run `37290930368`, head `ba744a3475dd099c085ba336daeb69f267ffe204`: `.table-scroll` має `364 px` ширини та `scrollWidth=1135/clientWidth=364/overflow-x:auto`; `.panel` має `366 px`, `scrollWidth=364/clientWidth=364/overflow-x:hidden`; оболонка й `body` мають `390 px` і `scrollWidth=390`, тоді як `html.scrollWidth=1008`.
- Це виключає таблицю та її обгортку як прямий витік ширини в документ. Скриншот `mobile-light/users.png` показує розширений full-page canvas, але не визначає джерело. Фіксовані `.role`/`.branches` не оголошуються причиною.
- Вузький job у CI run `37293522727`, head `9fd29dcb009bdefa43112841ddb8c2c75bff6e27`: тимчасове приховування `table.mat-mdc-table`, `.table-scroll` або `.panel` окремо зменшує `html.scrollWidth` з `1008` до `390`; приховування sidebar чи `.mascot-stage` лишає `1008`.
- Перша CSS-гіпотеза не пройшла CI: тільки видалення `.panel { overflow:hidden; }` лишало document overflow `618 px` на 390 px та `633 px` на 375 px; тимчасові зміни діагностики прибрані.
- Локальний компільований DOM probe: таблиця має intrinsic width `1126 px` всередині `.table-scroll` `364 px` (`scrollWidth=1126`, `overflow-x:auto`), `.panel` — `366 px`; звичайне `overflow:hidden` на `.panel` не прибирає document overflow. Reversible `contain:paint` на `.table-scroll` зменшує root `scrollWidth` з `1002` до viewport `390 px`, не змінюючи вмісту або внутрішнього горизонтального scroll.
- Фікс: `contain: paint` для Users `.table-scroll`; regression перевіряє сторінковий overflow та можливість горизонтально прокрутити колонки.
- Перевірено локально: `npm run e2e:lint`, production build і targeted Users parity для mobile-light + mobile-dark успішні. Перевірки охопили 390/375 px, таблицю та інвентар; відкриття role/branch overlay і доступність правої action-кнопки після прокрутки. Build показав наявні budget warnings; E2E запуск без застосункової БД.
- Попередній повний CI `37294470282` на `bbca41419eadf825da16842ed8c3b27e3f918c88`: усі job успішні, крім UI parity; саме цей невдалий експериментальний diff не є фінальним результатом. Усі тимчасові layout/CI діагностики прибрані.
- `users.mobile` видалено з overflow allowlist; фінальна перевірка охоплює desktop 1440 px у двох темах, 390/375 px у двох темах і збереження всіх колонок/дій.
- Контекст з KB: інструменти бази знань у цій сесії недоступні; використано `docs/guides/ui-parity.md`, тестові виміри DOM у CI та код Users.
- Наступна дія: зафіксувати й відправити виправлення у PR #158, запустити повний CI на новому head та передати його на незалежний огляд.
- Блокери: немає.

## План і критерії приймання
1. Прибрати Users override і залишити всі колонки/контроли доступними у внутрішньому scroller-і.
2. Перевірити 375 і 390 px у світлій та темній темах, 1440 px desktop, повний інвентар і відсутність page overflow.
3. Прибрати тільки `users.mobile` з allowlist та зберегти інші перевірки layout.
4. Оновити документацію Users і worklog; пройти E2E lint і повний CI.

## Докази
- `frontend/e2e/layout.ts` рахує `document.scrollWidth - clientWidth`.
- `frontend/e2e/parity.pw.ts` пропускає лише pageOverflow для ключа в allowlist, але продовжує перевіряти обрізані елементи.
- `frontend/e2e/__snapshots__/users.mobile.json` залишається еталоном інвентарю: його не можна послабити або видалити елементи.
