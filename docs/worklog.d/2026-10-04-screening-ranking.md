---
date: 2026-10-04
area: Recruiting
---
Добавлена обратимая сортировка кандидатов по сохранённому баллу ИИ в списке и колонках доски; нулевой балл идёт перед отсутствующей оценкой, новая незавершённая попытка скрывает старый результат.
Оценка, фильтры и порядок учитывают только отклики, доступные текущей роли; чужие филиалы и назначения интервьюера не влияют на видимую оценку. Фильтры и сортировка списка применяются до пагинации; этапы, личная раскладка и решения рекрутера не меняются — [recruiting.md](modules/recruiting.md).
CI verified six PostgreSQL endpoint regressions and full frontend tests. UI review corrected the compressed ranking control; inventory parsing preserves YAML-quoted accessible names containing score labels.
