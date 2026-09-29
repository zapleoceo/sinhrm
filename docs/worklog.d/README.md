# Журнал работ: фрагменты

Каждый PR добавляет **один новый файл** в эту папку вместо строки в `docs/worklog.md`.
Общий файл никто не правит, поэтому параллельные PR не конфликтуют.
После мержа в `main` workflow `worklog-build` собирает таблицу в [worklog.md](../worklog.md) (новые сверху)
и коммитит её от `github-actions[bot]` с `[skip ci]`.

## Формат

Имя: `docs/worklog.d/<YYYY-MM-DD>-<slug>.md`, slug — латиница в нижнем регистре, цифры, дефис
(например `2026-09-29-recruiting-board-dnd.md`).

```markdown
---
date: 2026-09-29        # YYYY-MM-DD, обязательно
area: Recruiting        # модуль/область, обязательно
pr: 93                  # номер PR, необязательно (бот возьмёт из squash-коммита «… (#93)»)
---
Что изменилось, 1–3 строки простым текстом — [recruiting.md](modules/recruiting.md)
```

- Ссылки пишутся относительно `docs/` (строка попадает в `docs/worklog.md`): `modules/<модуль>.md`, `guides/…`.
- В таблице: `Дата | <area>: <текст в одну строку> | #PR`.

## Проверка в CI (job `worklog`)

PR проходит, если добавляет валидный фрагмент, **или** имеет метку `no-worklog` (после установки метки —
Re-run job), **или** меняет только `docs/` и `.github/`, **или** автор — dependabot.
Невалидный фрагмент (нет `date`/`area`, пустой текст, больше 3 строк, неверное имя) — всегда ошибка.

Локально (необязательно): `node scripts/worklog-build.mjs` — пересобрать таблицу,
`node scripts/worklog-build.mjs --check` — проверить, что таблица актуальна,
`node --test scripts/worklog.test.mjs` — тесты проверки.
