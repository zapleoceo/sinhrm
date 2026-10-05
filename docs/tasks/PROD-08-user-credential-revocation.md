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
- Крок: реалізація та regression tests готові; branch `fix/user-block-credential-revocation`, база `origin/main` `3b4ac30`.
- Виконано: repository lockAndRefresh/revokeCredentials, стабільний порядок superadmin row locks; status і revoke під спільною transaction, separate session DB fail-closed. Unit делегування/повторний block/error і 7 feature regression scenarios; Users docs/worklog оновлено. Employee/Auth code не змінювалися.
- Перевірки: git diff --check пройшов. PHP у PATH відсутній; локально phpunit/Pint/PHPStan не запускалися, середовище не встановлювалося. Повні перевірки та тести виконує CI.
- Наступна дія: commit/push, draft PR/attach; root перевіряє точний SHA CI та організовує Luna/Astra рев'ю перед будь-яким release.
- Межі: тільки DB sessions у тій самій DB connection; не обіцяємо all-drivers. Запити, автентифіковані до block, не скасовуються. Employee semantics не змінюються. Без локального server/full suite, merge/deploy/preview/live OAuth. SOL/Luna/Astra дозволені власником.
