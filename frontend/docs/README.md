# SinHRM Web — документация

Документация фронтенда SinHRM (Angular SPA). Бэкенд — отдельный проект [sinhrm.api](https://gitlabs.itsrv.xyz/dev/sinhrm.api); его решения (ADR), архитектура, безопасность, MySQL-переезд, передача проекта и продуктовое ТЗ лежат там: [docs/README](https://gitlabs.itsrv.xyz/dev/sinhrm.api/-/blob/master/docs/README.md).

Эта папка — единственный источник встроенной справки приложения («Довідка»): сборка (`npm run build`) превращает `modules/`, `guides/` и `worklog.d/` в `public/help/docs.json`.

## С чего начать
| Кому | Что читать |
|---|---|
| Руководитель / рекрутер | [Обзор модулей](modules/README.md) — разделы «Что это и зачем» и «Как пользоваться» видны в приложении |
| Разработчик | [Правила разработки](guides/development.md) → [Деплой](guides/deploy.md) → [UI parity](guides/ui-parity.md) → [Таблицы](guides/tables.md) → [Экономия контекста агентов](guides/agent-efficiency.md) |
| Что сделано | [Журнал работ: как вести](worklog.d/README.md) · [история до фрагментов](worklog.md) |

## Заметки о разделении проекта
- Страницы `modules/<модуль>.md` есть в обоих проектах: здесь — пользовательские разделы и устройство фронта, в [sinhrm.api](https://gitlabs.itsrv.xyz/dev/sinhrm.api/-/blob/master/docs/modules/README.md) — устройство API. Меняешь модуль на фронте — правь эту страницу.
- Пути вида `app/Modules/...`, `tests/...`, `config/...` в модульных страницах относятся к репозиторию бэкенда; пути `src/app/...`, `e2e/...`, `scripts/...` — к этому.
- Ссылки на ADR, архитектуру, продуктовые документы и бэкенд-руководства ведут в репозиторий бэкенда.
