# PROD-44: Users mobile page overflow

## Стан
- Етап: функціональний фікс PR158 exact head bbca41419eadf825da16842ed8c3b27e3f918c88 об’єднаний локально в PR156; source CI 37294470282 ще в роботі, combined CI та shipping screenshots очікуються. Static Astra review exact head — PASS.
- База: `main` `38d90eaada1c43363cd64c2f16d7ceaecb611dab`; гілка `fix/users-mobile-overflow`.
- Підтверджено CI run `37289104847`, head `35658e8850bb3814b5e1b9f9498fcb9a1ac6975d` (усі 10 job успішні): viewport/client width `390 px`, document scroll width `1008 px`; таблиця має rect `x=13..1148 px`, ширину `1135 px`.
- Підтверджено CI run `37290930368`, head `ba744a3475dd099c085ba336daeb69f267ffe204`: `.table-scroll` має `364 px` ширини та `scrollWidth=1135/clientWidth=364/overflow-x:auto`; `.panel` має `366 px`, `scrollWidth=364/clientWidth=364/overflow-x:hidden`; оболонка й `body` мають `390 px` і `scrollWidth=390`, тоді як `html.scrollWidth=1008`.
- Ці попередні виміри не локалізували власника витоку ширини; подальша причинна ізоляція довела причетність Users table/panel. Скриншот `mobile-light/users.png` показує розширений full-page canvas, але не визначає джерело. Фіксовані `.role`/`.branches` не оголошуються причиною.
- Вузький job у CI run `37293522727`, head `9fd29dcb009bdefa43112841ddb8c2c75bff6e27`: тимчасове приховування `table.mat-mdc-table`, `.table-scroll` або `.panel` окремо зменшує `html.scrollWidth` з `1008` до `390`; приховування sidebar чи `.mascot-stage` лишає `1008`.
- Причина підтверджена: Users задавав `.panel { overflow:hidden; }`, перекриваючи глобальний `.panel { overflow-x:auto; }`. Обгортка таблиці вже є горизонтальним scroller-ом; локальне перекриття видалене.
- У фінальному diff прибрано тимчасову діагностику, `users.mobile` видалено з overflow allowlist; перевірка Users тепер охоплює 390/375 px у двох темах, збереження інвентарю при 375 px і desktop 1440 px у двох темах.
- Контекст з KB: інструменти бази знань у цій сесії недоступні; використано `docs/guides/ui-parity.md`, тестові виміри DOM у CI та код Users.
- Наступна дія: після легких merge guards зафіксувати та відправити combined PR156; перевірити повний CI і Users screenshots 375/390/1440 у двох темах.
- Блокери: source/combined UI-parity and full CI results, rendered Users screenshots at 375/390/1440, and combined Astra review.

## План і критерії приймання
1. Прибрати Users override і залишити всі колонки/контроли доступними у внутрішньому scroller-і.
2. Перевірити 375 і 390 px у світлій та темній темах, 1440 px desktop, повний інвентар і відсутність page overflow.
3. Прибрати тільки `users.mobile` з allowlist та зберегти інші перевірки layout.
4. Оновити документацію Users і worklog; пройти E2E lint і повний CI.

## Докази
- `frontend/e2e/layout.ts` рахує `document.scrollWidth - clientWidth`.
- `frontend/e2e/parity.pw.ts` пропускає лише pageOverflow для ключа в allowlist, але продовжує перевіряти обрізані елементи.
- `frontend/e2e/__snapshots__/users.mobile.json` залишається еталоном інвентарю: його не можна послабити або видалити елементи.
