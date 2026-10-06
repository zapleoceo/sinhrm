# PROD-25 — application-scoped ranking

Внутренний ID, не Jira-карточка. Task state восстановлен после передачи ранее подготовленной PR139 ветки.

## Контекст з KB

Предыдущий поиск проекта в KB вернул HTTP502. Источник — текущий Recruiting Scope, shared-candidate HTTP tests и независимый вывод Luna о скрытых applications в score aggregate; отсутствие KB не блокирует исправление.

## Стан

- Час: 2026-10-05 Asia/Saigon.
- Worktree: D:/Projects/sinhrm-wt/tz6-ranking; branch feat/tz6-screening-ranking; исходная feature227046d; tested implementation head cc7b9ff04dc36a61215fb7981aa9edf083e03719.
- Текущий шаг: feature исправлена, отдельная [PR139](https://github.com/zapleoceo/sinhrm/pull/139), подготовка combined regression.
- Сделано: application visibility применяется до max/filter/order; eager relations и whereHas ограничены той же Scope. Helper идентичен подготовленной PR153 версии. Отрицательные hidden vacancy/stage/status, owner-only, branch/manager/interviewer/admin, null/ties/pagination tests; docs/worklog обновлены.
- Evidence: cc7b9ff10 checks SUCCESS;1369 backend tests/12496 assertions/95.1% coverage; независимый Astra local/GitHub PASS exact head. Локальный PHP/DB runtime не поднимался. State-only добавление требует fresh exact-head CI/review.
- Следующий шаг: state delta CI/review и совместная проверка с PR153/остальными source branches; merge/deploy не выполнялись.
- Блокеры: нет code blocker; live UI/release принимаются по проверенному пакету.
