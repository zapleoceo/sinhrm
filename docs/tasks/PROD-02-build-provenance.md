# PROD-02 — Build provenance

## План
1. Проверить health и deploy checkout.
2. Генерировать только SHA реального checkout перед сборкой API/Web.
3. Сохранить health контракт, добавить тесты отсутствующего/некорректного SHA и CI stamping.
4. Обновить документацию, targeted проверки, draft PR; без deploy/merge.

## Контекст з KB
Предыдущий поиск Rovo SinHRM вернул только несвязанный Lamas; Drive — без результатов. Для PROD-02 запрошен cloudId у оркестратора; план запроса: SinHRM build provenance health deployed SHA. Источник архитектуры: CLAUDE.md, docs/guides/development.md, deploy.yml.

## Стан
- Крок: исследование завершено, реализация.
- Доказательства: origin/main 3b4ac30; deploy checkout использует workflow_run.head_sha, health APP_VERSION по умолчанию dev.
- Рішення: generated JSON, SHA из git rev-parse HEAD; никакого runtime git или нового обязательного env.
- Далі: targeted проверки и draft PR.
- Блокери: отсутствуют; live проверка после разрешённого deploy.
- Час: 2026-10-05 Asia/Saigon.

### Проверки и handoff
Targeted Node tests stamping: 2/2; deploy-gate 34/34 tests.
Локальный PHP отсутствует в PATH; HealthTest/BuildVersionTest, Pint/PHPStan и full suite проверит CI.
KB cloudId отсутствует; оркестратор подтвердил предыдущий поиск без результатов. Основной контекст — текущий repo и UNIFIED-TZ.
Live проверки отсутствуют: deploy/preview не запрошены. Следующий шаг — draft PR CI и независимое ревью.

