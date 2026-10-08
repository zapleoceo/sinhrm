# SinHRM — документация

**Что это.** SinHRM — отдельная система для рекрутеров и HR. Цель — проверяемый путь от заявки на подбор
до решения и передачи нанятого человека в HR: источник, история, ответственный и следующий шаг сохраняются.
[Единое продуктовое ТЗ](product/UNIFIED-TZ.md) описывает направление и предлагаемый первый пилот для согласования;
его объём, эффект и внешние интеграции ещё требуют проверки.

Проект тестовый и некоммерческий. Код открыт (публичный репозиторий), персональные данные — в базе, ключи запуска — в окружении Vercel, секреты интеграций — в зашифрованном хранилище базы. Подробнее — [секреты и данные](architecture/secrets.md).

## С чего начать
| Кому | Что читать |
|---|---|
| Владелец продукта / вся команда | [Единое продуктовое ТЗ: цель, предлагаемый пилот и критерии приёмки](product/UNIFIED-TZ.md) → [Свидетельства аудитории и пробелы исследования](product/audience-evidence.md) → [Задачи до production и раунды](product/production-backlog.md) |
| Руководитель / рекрутер | [Обзор модулей](modules/README.md) |
| Разработчик | [Архитектура](architecture/overview.md) → [Правила разработки](guides/development.md) → [Деплой](guides/deploy.md) → [Хенд-офф приложения для Itstep](guides/itstep-app-handoff.md) → [API: документация и .http-примеры](guides/api.md) → [UI parity: страховка для рестайла](guides/ui-parity.md) → [Таблицы: сортировка и фильтры](guides/tables.md) → [Ревью SOLID/DRY, октябрь 2026](guides/architecture-review-2026-10.md) |
| Про безопасность | [Секреты и персональные данные](architecture/secrets.md), [Журнал ошибок и заголовки безопасности](architecture/observability.md), [Аудит безопасности 2026-10](security/audit-2026-10.md) |
| Восстановление | [Backup/restore: доказательство CI и runbook](guides/backup-restore.md) → [Переезд на MySQL: перенос данных, переключение, откат](guides/mysql-cutover.md) |
| Решения и почему | [ADR](adr/): [0001 стек и хостинг](adr/0001-hosting-and-stack.md) · [0002 AI через AI Broker](adr/0002-ai-via-ai-broker.md) · [0003 каналы: один путь приёма](adr/0003-channels-single-ingestor.md) · [0004 воркфлоу на cron](adr/0004-workflows-on-cron.md) · [0005 секреты в БД](adr/0005-secrets-in-db-secretvault.md) · [0006 cron в GitHub Actions](adr/0006-cron-via-github-actions.md) · [0007 анонимность](adr/0007-anonymity-pulse-safe-speak.md) · [0008 бюджет деплоев](adr/0008-vercel-hobby-deploy-budget.md) · [0009 выключатель модулей](adr/0009-module-access.md) |
| Что сделано | [Журнал работ: как вести](worklog.d/README.md) · [блокеры и история](worklog.md) |

## Как устроено одним абзацем
Интерфейс — Angular-приложение на `sinhrm.vercel.app`. Все запросы `/api/*` браузер отправляет на тот же адрес,
а Vercel незаметно пересылает их в API на Laravel (`sinhrm-api.vercel.app`). API хранит данные в MySQL 8.4
([ADR 0011](adr/0011-mysql-only.md)). Фоновые задачи (почта, напоминания) запускаются по расписанию из GitHub Actions.
