# PROD-44: Users mobile page overflow

## Стан
- Етап: знайти елемент, що розширює корінь документа поза таблицею.
- База: `main` `38d90eaada1c43363cd64c2f16d7ceaecb611dab`; гілка `fix/users-mobile-overflow`.
- Підтверджено CI run `37289104847`, head `35658e8850bb3814b5e1b9f9498fcb9a1ac6975d` (усі 10 job успішні): viewport/client width `390 px`, document scroll width `1008 px`; таблиця має rect `x=13..1148 px`, ширину `1135 px`.
- Підтверджено CI run `37290930368`, head `ba744a3475dd099c085ba336daeb69f267ffe204`: `.table-scroll` має `364 px` ширини та `scrollWidth=1135/clientWidth=364/overflow-x:auto`; `.panel` має `366 px`, `scrollWidth=364/clientWidth=364/overflow-x:hidden`; оболонка й `body` мають `390 px` і `scrollWidth=390`, тоді як `html.scrollWidth=1008`.
- Це виключає таблицю та її обгортку як прямий витік ширини в документ. Скриншот `mobile-light/users.png` показує розширений full-page canvas, але не визначає джерело. Фіксовані `.role`/`.branches` не оголошуються причиною.
- Вузький job у CI run `37291880875` пройшов тест discovery і виконав Users mobile-light. Два зовнішні кандидати: закрита fixed sidebar `x=-321..0` та fixed `.mascot-stage` `x=180..420`; rect-и цих оболонок не пояснюють `html.scrollWidth=1008`.
- Контекст з KB: інструменти бази знань у цій сесії недоступні; використано `docs/guides/ui-parity.md`, тестові виміри DOM у CI та код Users.
- Підтверджено: окремий diagnostic job на `ba744a3475dd099c085ba336daeb69f267ffe204` завершився `No tests found` через надто точний grep; повний UI parity job у тому самому CI завершився успішно й дав виміри ланцюжка вище.
- Підтверджено: job на `f3773a35b11310d03e11e71d99633e8e93efb8ad` зупинився на `e2e:lint` через дубльовані поля діагностичного типу; геометрії цього head немає.
- Наступна дія: виправлений narrow job індивідуально приховує/відновлює таблицю, її scroll container, панель, sidebar і mascot stage та порівнює `html.scrollWidth`.
- Блокери: джерело `html.scrollWidth=1008` ще не встановлено; CSS не змінено.

## План і критерії приймання
1. Для Users на 390 px записати у CI-лог геометрію документа та предків таблиці; лише селектори, класи, розміри й computed layout, без тексту рядків/PII.
2. Виправити доведену причину на вузьких екранах, зберігши всі колонки, поля, дії та переклади.
3. Додати перевірку відсутності сторінкового overflow для Users, прибрати тільки `users.mobile` з allowlist; зберегти перевірки інвентарю та обрізаних контролів.
4. Оновити документацію Users і worklog; перевірити відповідний e2e lint і повний обов'язковий UI parity CI.

## Докази
- `frontend/e2e/layout.ts` рахує `document.scrollWidth - clientWidth`.
- `frontend/e2e/parity.pw.ts` пропускає лише pageOverflow для ключа в allowlist, але продовжує перевіряти обрізані елементи.
- `frontend/e2e/__snapshots__/users.mobile.json` залишається еталоном інвентарю: його не можна послабити або видалити елементи.
