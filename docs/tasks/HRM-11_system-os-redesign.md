# HRM-11 — редизайн всей системы в Browser OS

План, scope и инварианты: ../ai-ui/tasks/system-os-redesign/task-context.md; implementation-plan.md. Jira — историческая связанная задача, её статус не приравнивается к выполнению нового объёма.

## Контекст з KB

Jira read-only29.10: HRM-11 (To do), HRM-84 unrelated superadmin. Прямая новая инструкция владельца и согласовательный v5 — текущая презентационная спецификация. Рабочее приложение — источник прав/поведения. Независимый preflight выявил зависимости moduleGuard/globalquery/navigation для multipleoutlets: сначала foundation, потом guardedroutingbridge; не подменять многоконность заглушками.

## Стан

2026-10-30 00:53 Asia/Saigon. Foundation: `D:/Projects/HRM/worktrees/browser-os-system`, `feat/browser-os-foundation`, base `899d4dab`, draft PR [247](https://github.com/zapleoceo/sinhrm/pull/247), head `d8e1d298`. Full frontend unit/lint/build, backend, extension, docs and worklog checks passed. Current CI `40924284532` still runs UI parity. Prior CI `40921259802` produced actual four-viewport screenshots; SOL inspected eight images. The new appearance inventory and one added user-menu action were recorded from actual CI evidence. Strict select-value readiness and a corrected unsupported prefixed-filter assertion await fresh CI. No whole-system visual or runtime performance acceptance yet.

Separate work: desktop/widgets draft PR [249](https://github.com/zapleoceo/sinhrm/pull/249), head `3380a556`; its first CI found docs/build failures and Luna is correcting them. Two-route real-window pilot remains in `feat/browser-os-windows`; SOL accepted five corrections, while role-only security-scope invalidation still requires a correction and regression. Dependency remediation draft PR [248](https://github.com/zapleoceo/sinhrm/pull/248), head `19bcc45e`, passes security/build/unit/backend/docs checks; UI parity remains pending. Each branch starts from the same verified main base. Independent exact combined review, integration of desktop/window launch, remaining route families, production-build screenshots and performance checks remain required.

Next: resolve bounded source/CI failures, publish reviewed window pilot, then prepare a Luna-authored combined candidate and expand route compatibility. Entire Browser OS redesign remains in progress. Main and production are unchanged; merge/deploy are not authorized. Actual GitHub approval, live-provider acceptance and measured performance are not claimed.
