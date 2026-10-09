---
date: 2026-10-09
area: Scripts
---
Откат миграции `generalize_tasks_table` на MySQL 8.4 сначала снимает внешний ключ, затем индексы и столбцы; тест проверяет `down()` и повторный `up()` — [scripts.md](modules/scripts.md).