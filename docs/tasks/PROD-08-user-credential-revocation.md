# PROD-08 — Відкликання доступу при блокуванні користувача

## Контекст з KB
- 2026-10-05: Rovo query `SinHRM user blocked sessions personal access tokens revocation`, itstep site, HTTP 502; зовнішня KB недоступна.
- Luna висновок від root: UserAdminService зберігає Blocked без відкликання credentials; middleware завершує лише поточну сесію; PAT deleteAll обмежений одним ім'ям.
- Repo підтверджує: `session.driver` default database, `sessions.user_id`, Sanctum polymorphic tokens, remember_token; Users transaction блокує superadmin rows.

## План
1. UserAdminRepository: під тією самою транзакцією блокувати/оновити target з актуальної БД, відкликати всі його DB sessions, PAT усіх імен і remember token.
2. UserAdminService викликає відкликання при явному Blocked (включно з повторним блокуванням); Active змінює лише статус і не відновлює credentials.
3. Unit делегування/guard, feature endpoint: друга недоторкана сесія, кілька PAT, старий remember-cookie, після unblock доступ не відновлюється; unrelated user збережений; rollback failure/repeated block/current target state.
4. Документація/worklog, точкові локальні checks за доступності PHP; повні suite у CI; commit/push, draft PR і attach. Root забирає CI та незалежне рев'ю.

## Стан
- Час: 2026-10-05, Asia/Saigon.
- Крок: PAT і durable web-session grant race виправлені у draft PR149; очікується CI точного head та Astra review.
- Виконано: атомарні status/revoke DB sessions/all PAT/remember-token; target lock і last-active-superadmin guard; credential_version increment на block, serialized fresh-active/version Auth grants; durable session stamp і legacy-session invalidation. Unblock credentials не повертає; Employee semantics незмінні.
- Регресії: second session/multiple PAT/remember/unrelated user/rollback/repeated block/guards; stale actor після unblock не створює і не замінює PAT; late session write і legacy session відхиляються; новий explicit Google login після unblock дозволений. DB session fixtures відповідають JSON serialization проекту.
- Перевірки: PHP відсутній у PATH; phpunit/Pint/PHPStan та migration up/down локально не запускалися. CI виконає full suite/forward migration; down не підтверджено. git diff --check пройшов.
- Наступна дія: commit/push PR149, root перевіряє CI та незалежне рев'ю; потім PR146 fixture translation, лише після цього PROD09.
- Межі: database sessions на спільній connection, не all drivers; вже виконувані запити не скасовуються. Migration перед code release; legacy durable sessions вимагають нового login. Без local server/full suite/merge/deploy/preview/live OAuth.
- KB: Rovo HTTP502 не блокує авторизовану роботу. SOL writer дозволений власником.

Upgrade safeguard: restoring a legacy Blocked account with credential_version=0 atomically revokes its old sessions/PAT/remember-token and advances version before Active. Normal unblock after a new explicit block changes status only. Upgrade-like feature regression preserves healthy users and rejects all old credentials without a new block first. CI pending.
