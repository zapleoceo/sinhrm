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

### Follow-up — CSS component budgets

- Время: 2026-10-30 14:08 Asia/Saigon.
- Worktree: `D:/Projects/HRM/worktrees/gitlab-glass-range`, branch `feat/os-desktop-reference`, verified source `9519c145e3d6c4ab3dae0710dfc2fa74ce575b74`.
- Задача: устранить превышение component-style бюджета, обнаруженное CI после source review: `desktop.page.scss` 10.91 KB (нужно уменьшить минимум на 2.91 KB), `shell.layout.scss` 8.81 KB (минимум на 0.81 KB). Разрешённые файлы — только эти два стиля и данный state.
- Выполнено: удалена устаревшая 12-column widget-grid каскадная сетка; S/M/L ширина, плотность и метрики, 44px controls, mobile width и container-query layout сохранены. Каталог ярлыков выделен в standalone `DesktopAppLauncherComponent` с типизированными `groups` и `launch` output; авторизация и запуск маршрутов остались у родителя. Dock цвета используют общий фон и 6 group color variables. Из shell cascade удалены дублирующие Chrome declarations и правила для неиспользуемого `.burger`.
- Проверки/оценка CSS: final Sass compressed bytes: `desktop.page.scss` 7,183 / 8,000; `desktop-app-launcher.component.scss` 2,616 / 8,000; `shell.layout.scss` 7,795 / 8,000. Targeted `desktop.page.spec.ts` — 10/10; `ng lint` — pass; `npm run e2e:lint` — 7/7 pass (существующее предупреждение Node о `e2e/inventory.ts`); `git diff --check` — pass. Angular production build не запускался локально.
- Ограничение: скриншоты/production CSS budget и визуальное сравнение требуют CI/preview; Sass compressed estimate оставляет 205 bytes запаса у shell stylesheet.
- Следующий шаг: передать exact diff на SOL review и CI; не коммитить до review.
