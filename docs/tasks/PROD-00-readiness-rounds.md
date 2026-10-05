# PROD-00 — backlog до production и работа раундами

## План

Сверить актуальный GitHub/CI/refs и сохранённые drafts; записать конечные release criteria и все хвосты; начать независимые задачи раунда1, затем последовательно продолжать задачи без бизнес-блокеров. Содержательные docs исправления в той же документационной ветке. Код пишут SOL, выводы сверяет Luna, локальный и GitHub diff перед merge — Astra.

## Контекст з KB

Запрос Vera `SinHRM production readiness D1 D2 D3 terminate User unblock secrets rotation` 2026-10-05 вернул нерелевантные общие записи; продуктового решения D1–D3 нет. Предыдущая Jira/Drive разведка SinHRM не нашла первичные TZ/interviews. Использованы текущий main3b4ac30, public PR138–144, UNIFIED-TZv0.1, отдельный v0.3 draft0dd175d, lifecycle draft и локальный статус-аудит. KB не блокирует разрешённую работу, а draft не подменяет решение владельца.

## Стан

- 2026-10-05: phase planning/execution раунд1.
- Сделано: refs/main/openPR сверены; создан [backlog](../product/production-backlog.md); ownership PROD-01/02 назначен SOL, Luna — независимая диагностика/backlog review.
- Следующий шаг: закончить docs consistency → commit/draft PR → CI/Astra; продолжить PROD-03/08/15/17 по результатам первого раунда.
- Блокеры: D1–D3 и внешние gates не разрешены автоматически; production release проводится по конкретному проверенному пакету.
- Evidence: status audit2026-10-05, main3b4ac30, combined31bf076/CI37232005803. Тесты/код в этом docs task не изменены.
