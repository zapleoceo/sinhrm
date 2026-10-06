# PROD-00 — backlog до production и работа раундами

## План

Сверить актуальный GitHub/CI/refs и сохранённые drafts; записать конечные release criteria и все хвосты; начать независимые задачи раунда1, затем последовательно продолжать задачи без бизнес-блокеров. Содержательные docs исправления в той же документационной ветке. По последнему указанию владельца исполнение передано Luna; локальный и GitHub diff перед merge проверяет независимый Astra.

## Контекст з KB

Запрос Vera `SinHRM production readiness D1 D2 D3 terminate User unblock secrets rotation` 2026-10-05 вернул нерелевантные общие записи; продуктового решения D1–D3 нет. Предыдущая Jira/Drive разведка SinHRM не нашла первичные TZ/interviews. Использованы текущий main3b4ac30, public PR138–144, UNIFIED-TZv0.1, отдельный v0.3 draft0dd175d, lifecycle draft и локальный статус-аудит. KB не блокирует разрешённую работу, а draft не подменяет решение владельца.

## Стан

- 2026-10-05: phase final expanded validation; worktree `D:/Projects/sinhrm-wt/production-readiness`, branch `docs/production-readiness`, source before status update `7fbd601`, main `38d90ea`.
- Сделано: refs/main/openPR сверены; создан [backlog](../product/production-backlog.md), docs/worklog guards прошли; docs draft PR147. Luna PASS для8496d2b, рекомендация сужения S-критериев учтена. SOL создал PR145 (PROD-02,10 checks SUCCESS) и146 (PROD-01, exact-head CI идёт). Luna подтвердила timezone scheduler bug и сохранение старых credentials после unblock; SOL начал отдельные PROD-03/08 ветки.
- Следующий шаг: получить финальные CI/restore/Astra расширенного156c39663d (CI37297982962/37297982983) и завершить desktop render review158ccb3; новый docs status delta147 требует docs/worklog guards и независимого ревью.156d23 ранее прошёл CI/restore/Astra, но новый head принимается отдельно.157f006 исправил все CI/Astra findings:10 checks SUCCESS и prep-code PASS;158ccb3:10 checks SUCCESS, Astra static/mobile PASS. Финальные139/153 и155 CI зелёные;155 Astra PASS.1477fbd имеет10 CI и Astra PASS до текущего status update.154 rules merged38d90ea; common/actual-worktree adapter pinb269a264 прочитаны.
- Блокеры: upstream service/response/namespace/token/live acceptance записаны в долги; владелец пока не имеет ссылки и разрешил подготовить код, inputs предоставит позже. Source-scoped mapping/права и D1–D3 не утверждены; автоматическое изменение employees/ACL из неизвестного feed не разрешено выбранным контрактом. Инфраструктура/backup/alerts принадлежат DevOps. Model capacity больше не текущий блокер. Shared workflow не добавляет автоматическое разрешение release.
- Новое evidence: Sintegrum API c70243a/frontend9e12132 исследованы read-only и независимо перепроверены Luna; local password/opaque tokens и outgoing hire sync подтверждены, SSO/full feed не доказаны. User superadmin подтверждён.157 typed gateway/read-only synthetic preview не пишет сотрудников/ACL и не заявляет live SDK. Root наблюдал реальные375 Users и combined mobile synthetic Integrations PNG; Astra отдельным агентом проверяет финальный набор. Тесты/код в этом docs task не изменены; deployment/merge не выполнялись.
- Evidence: status audit2026-10-05, main3b4ac30, combined31bf076/CI37232005803. Тесты/код в этом docs task не изменены.
