# HRM-13 — рабочий стол в стиле OS

## План

1. Добавить на рабочий стол группы ярлыков из текущего каталога `DESKTOP_APPS`, сохранив существующие фильтры по ролям и включённым модулям.
2. Разместить существующие API-виджеты в правой колонке на широком экране, оставить их управление и мобильную адаптацию.
3. Усилить оформление фоновой поверхности, цветовых акцентов и Dock в рамках текущих токенов темы и быстрого режима.
4. Добавить/уточнить точечные проверки доступности, запуска реальных маршрутов, responsive layout и сохранить существующие проверки окон.

## Стан

- Время: 2026-10-30 Asia/Saigon.
- Worktree: `D:/Projects/HRM/worktrees/gitlab-browser-os`, branch `feat/os-desktop-reference`, база `75db9708287283ac1efd09aa51e3c59ec740ef92`.
- Источник дизайна: пользовательский скриншот `D:/Temp/codex-clipboard-4c15a813-3912-42f6-9635-7078b175bf3e.png`.
- Границы: только существующие приложения/группы и API-виджеты; новых финансовых/CRM-метрик, фиктивных данных, маршрутов, прав и оконных алгоритмов не добавлять.
- Выполнено: добавлен responsive лаунчер из доступных записей `DESKTOP_APPS`; пустые группы скрываются, обычные ссылки проходят через прежний `DESKTOP_LAUNCHER`. Существующие live widgets перемещены в правую колонку, на узких экранах область становится одной колонкой. Категорийные tile accents и статичный многослойный фон используют существующие theme/appearance tokens; fast выключает фон и тени. Dock уплотнён, а длинный список окон прокручивается внутри Dock. S/M/L сохраняют различия ширины, плотности и чисел в правой колонке.
- Сохранено: права `appAllowed`, виджетные `widgetAllowed`, `/api`-данные, реальные loading/error/empty states, drag/drop, keyboard reorder, сохранение layout, window routing, темы/палитры/шпалери/glass/solid и reduced/fast motion. Новых приложений и чисел не добавляли.
- Проверки: `npx ng test --watch=false --include ...desktop.page.spec.ts --include ...shell.layout.spec.ts` — 42/42; `npx ng lint` — pass; `npm run e2e:lint` — pass (включает проверку Playwright project coverage); `git diff --check` — pass. E2E browser/production build не запускались по bounded validation plan.
- E2E: добавлена проверка реального запуска приложения из рабочего стола, desktop rail и контролов при 768/1000/1440px с прикреплёнными скриншотами, а также mobile Dock internal scrolling. Accessibility snapshots `desktop.desktop.json` и `desktop.mobile.json` намеренно не угадывались: сверить и обновить после фактического CI inventory.
- Изменённые paths: desktop page/model consumer/styles/spec, shell Dock template/styles, `e2e/flows.pw.ts`, desktop/shell module help, worklog, HRM-12 closeout evidence и папка task `os-desktop-reference`.
- Следующий шаг: SOL review exact source, GitLab CI выполнить browser/production screenshot checks; обновить inventory snapshots только по CI actual inventory и повторить затронутые проверки.
