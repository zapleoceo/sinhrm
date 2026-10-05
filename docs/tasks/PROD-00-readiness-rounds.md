# PROD-00 — backlog до production и работа раундами

## План

Сверить актуальный GitHub/CI/refs и сохранённые drafts; записать конечные release criteria и все хвосты; начать независимые задачи раунда1, затем последовательно продолжать задачи без бизнес-блокеров. Содержательные docs исправления в той же документационной ветке. По последнему указанию владельца исполнение передано Luna; локальный и GitHub diff перед merge проверяет независимый Astra.

## Контекст з KB

Запрос Vera `SinHRM production readiness D1 D2 D3 terminate User unblock secrets rotation` 2026-10-05 вернул нерелевантные общие записи; продуктового решения D1–D3 нет. Предыдущая Jira/Drive разведка SinHRM не нашла первичные TZ/interviews. Использованы текущий main3b4ac30, public PR138–144, UNIFIED-TZv0.1, отдельный v0.3 draft0dd175d, lifecycle draft и локальный статус-аудит. KB не блокирует разрешённую работу, а draft не подменяет решение владельца.

## Стан

- 2026-10-05: phase execution/security + Itstep app integration discovery.
- Сделано: refs/main/openPR сверены; создан [backlog](../product/production-backlog.md), docs/worklog guards прошли; docs draft PR147. Luna PASS для8496d2b, рекомендация сужения S-критериев учтена. SOL создал PR145 (PROD-02,10 checks SUCCESS) и146 (PROD-01, exact-head CI идёт). Luna подтвердила timezone scheduler bug и сохранение старых credentials после unblock; SOL начал отдельные PROD-03/08 ветки.
- Следующий шаг: финальный state-delta CI139/153 и совместная регрессия Luna;155 runtime handoff CI/Astra; preparation-only код41 в отдельной ветке38d90ea.145/146/148/149/150/151/152 и implementation139/153 CI/Astra зелёные.154 rules merged38d90ea после10 green/Astra; shared common/actual-worktree sinhrm adapter pinb269a264 прочитаны.
- Блокеры: upstream service/response/namespace/token/live acceptance записаны в долги; владелец пока не имеет ссылки и разрешил подготовить код, inputs предоставит позже. Source-scoped mapping/права и D1–D3 не утверждены; автоматическое изменение employees/ACL из неизвестного feed не разрешено выбранным контрактом. Инфраструктура/backup/alerts принадлежат DevOps. Model capacity больше не текущий блокер. Shared workflow не добавляет автоматическое разрешение release.
- Новое evidence: Sintegrum API c70243a/frontend9e12132 исследованы read-only и независимо перепроверены Luna; local password/opaque tokens и outgoing hire sync подтверждены, SSO/full feed не доказаны. Добавлены PROD-39–42 и itstep-integration.md. User superadmin подтверждён. PR147 требует нового CI/Astra после документационных изменений.
- Evidence: status audit2026-10-05, main3b4ac30, combined31bf076/CI37232005803. Тесты/код в этом docs task не изменены.
