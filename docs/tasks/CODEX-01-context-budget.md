# CODEX-01 — экономия контекста

Внутренний task ID, не Jira-карточка. Источник: владелец приложил рекомендации 05.10.2026 и попросил применить их к SinHRM.

## Контекст з KB

Использован приложенный текст и текущие правила SinHRM. Предыдущий поиск внутренней KB по проекту возвращал HTTP502; нового внешнего контракта эта docs-only задача не вводит. Claude-specific настройки из текста не копируются в Codex.

## План

Короткий Codex entrypoint → один project guide для context/state/model/log/review/test правил → docs/worklog guards → draft PR/CI → независимый Astra review. Runtime hooks и глобальная конфигурация не меняются.

## Стан

- Час: 2026-10-05 Asia/Saigon.
- Worktree: D:/Projects/sinhrm-wt/context-budget; branch docs/context-budget; base3b4ac30.
- Текущий шаг: docs-only implementation.
- Сделано: AGENTS.md с коротким входом; guide с узкими заданиями, дисковым состоянием, краткими логами, точными review heads и обязательными тестами.
- Следующий шаг: docs/worklog guards, commit/push/draft PR, exact-head CI/Astra.
- Блокеры: отсутствуют; экономия лимита ещё не измерена.
