# HRM-15 — пользовательские обои рабочего стола

## План

1. Добавить ограниченное браузерное хранилище одного изображения отдельно от настроек темы.
2. Проверять JPEG/PNG/WebP по MIME и сигнатуре, декодировать, уменьшать до 2048 px и сжимать до локального лимита; ошибки и квота показываются в настройках.
3. Добавить доступные с клавиатуры выбор, предпросмотр, удаление и возврат к стандартному фону; сохранить текущие темы, палитры, glass/solid, fast mode и виджеты.
4. Покрыть обработку/персистентность тестами компонента и store, добавить E2E загрузки PNG, восстановления после перезагрузки, удаления и отказа формата с приложенными скриншотами.
5. Обновить модульную справку и worklog; выполнить точечные тесты, lint и E2E lint, затем передать неизменённый diff на SOL review и внешнюю CI.

## Стан

- Время: 2026-10-30 Asia/Saigon.
- Worktree: `D:/Projects/HRM/worktrees/gitlab-browser-os`, integration branch `release/os-desktop-appearance`, base `9519c145e3d6c4ab3dae0710dfc2fa74ce575b74`, merge commit `9ba5011b8f877fde07cb4addbd8550ddebd34603`.
- Область: custom wallpaper плюс совместимость с прозрачностью стекла `0..100`; без backend/remote URLs и без добавления image payload в appearance preference schema.
- Выполнено: добавлено отдельное локальное хранилище с проверкой сигнатуры/декодирования, resize до 2048 px, ограничением data URL, защитой от устаревшего параллельного save и восстановлением CSS-переменной при открытии рабочего стола. В настройках добавлены выбор, предпросмотр, удаление/стандартный фон и переведённые ошибки. Custom image независим от preference schema и остаётся статичным в fast mode.
- Проверки объединённого source: targeted theme/store/appearance/desktop specs — 29/29; `npx ng lint` — pass; `npm run test:docs` — 9/9; `npm run e2e:lint` — pass; `git diff --cached --check` перед merge commit — pass. Browser E2E/production build/full suite не запускались локально. Playwright сценарии прикрепляют wallpaper, appearance и transparency screenshots при CI запуске.
- SOL review custom wallpaper: PASS без остающихся P1/P2. Combined rendered review/screenshots ещё не получены.
- Следующий шаг: внешняя CI на combined merge commit, проверка прикреплённых скриншотов и обновление state по фактическим результатам.

### Follow-up — читаемость поверх обоев и E2E locale

- Время: 2026-10-30 14:24 Asia/Saigon.
- Worktree: `D:/Projects/HRM/worktrees/gitlab-browser-os`, branch `release/os-desktop-appearance`, исходный HEAD `4cc2e4da76578f00e062d88915dc5d6750bfde0c`.
- Выполнено: заголовок рабочего стола и заголовок виджетов используют непрозрачные theme-card surfaces поверх пользовательских изображений, независимо от glass opacity; E2E проверяет background и `has-user-wallpaper`. Неверный русский текст в украинском отказе загрузки заменён точным локализованным сообщением.
- Проверки после правок: `npm run e2e:lint` — 7/7; `npx ng lint` — pass; `npx ng test --watch=false --include=src/app/features/desktop/desktop.page.spec.ts` — 10/10; `npm run test:docs` — 9/9; `git diff --check` — pass. Browser E2E ожидает CI.
- Следующий шаг: выполнить быстрые проверки, получить SOL review и передать diff до коммита.
