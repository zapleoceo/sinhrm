# CODEX-01 — экономия контекста

Внутренний task ID, не Jira-карточка. Источник: владелец приложил рекомендации 05.10.2026 и попросил применить их к SinHRM.

## Контекст з KB

Использован приложенный текст и текущие правила SinHRM. Предыдущий поиск внутренней KB по проекту возвращал HTTP502; нового внешнего контракта эта docs-only задача не вводит. Claude-specific настройки из текста не копируются в Codex.

## План

Короткий Codex entrypoint → один project guide для context/state/model/log/review/test правил → docs/worklog guards → draft PR/CI → независимый Astra review. Runtime hooks и глобальная конфигурация не меняются.

## Стан

- Час: 2026-10-05 Asia/Saigon.
- Worktree: D:/Projects/sinhrm-wt/context-budget; branch docs/context-budget; base3b4ac30.
- Текущий шаг: published draft [PR154](https://github.com/zapleoceo/sinhrm/pull/154), final guards/CI.
- Сделано: AGENTS.md с коротким входом; guide с узкими заданиями, дисковым состоянием, краткими логами, точными review heads и обязательными тестами. Implementation61ce383: docs/worklog guards и diff check PASS; независимый Astra local/GitHub PASS на61ce383cc0d8a6e57e46cbe47d6920ced9f7e983. Runtime код не менялся, mirrored tests не требуются. State delta требует fresh exact-head review/CI.
- Следующий шаг: финальный CI/Astra на state delta, затем разрешённое применение PR. Фактические settings клиента не менялись; текущие агенты получили новые правила.
- Блокеры: отсутствуют; экономия лимита ещё не измерена.
