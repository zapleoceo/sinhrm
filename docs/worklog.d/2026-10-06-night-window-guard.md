---
date: 2026-10-06
area: CI
---
Необязательный workflow `night-window.yml`: раз в неделю и по кнопке гоняет backend phpunit под libfaketime на 22:30 UTC (01:30 Киев), ловит ошибки «UTC против Киева» — [guides/development.md](guides/development.md)
