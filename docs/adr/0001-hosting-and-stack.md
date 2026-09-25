# ADR 0001 — Стек и хостинг

**Статус:** принято (2026-09-26).

**Контекст.** Тестовый некоммерческий проект, бюджет $0, владелец выбрал Angular + Laravel и Vercel.

**Решение.** Angular SPA — Vercel; Laravel — Vercel через community-рантайм `vercel-php` (PHP 8.5);
Postgres — Neon Free (Frankfurt); CI/CD и cron — GitHub Actions; репозиторий публичный.

**Последствия.** Нет воркеров и постоянных соединений → очередь в БД + cron каждые 30 мин, только вебхуки
(Telegram userbot отложен до собственного сервера). `vercel-php` неофициальный — риск отставания версий.
Публичный репо → строгие правила секретов (docs/architecture/secrets.md).

**Альтернативы.** Oracle Always Free VM, сервер компании, Laravel Cloud — отклонены владельцем на этапе MVP.
