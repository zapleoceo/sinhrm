# HRM-12 — минималистичная шапка окон

## План

1. Упростить шапку и ручку изменения размера, сохранив действия и доступность.
2. Зафиксировать взаимодействия тестом и обновить справку/worklog.
3. Выполнить точечные проверки и передать точный diff на независимое ревью.

## Контекст з KB

- Передача координатора: выполнен ограниченный поиск Vera по предыдущим решениям Browser OS; два результата подтверждают уже принятые предпочтения по оформлению окон. Устаревшие превью не цитировались и не использовались как текущее состояние; текущий UI сверяется с staging и кодом этой ветки.

## Стан

- Время: 2026-10-30 Asia/Saigon.
- Источник: ветка `feat/minimal-window-chrome` от `be46503a3ab69e2265d8b54b4f7a73a70b3bcc83`; source commit `d8e7813731b79e09fea71febe50316ac1e43ecde`, tree `adadf6b0f7fce34c6983896da20c0fc8641af7d9`.
- Выполнено: шапка 44px на desktop и около 52px на mobile; 32px transparent hit targets с 12px цветными индикаторами и inline SVG, close/minimize/maximize слева, симметричное центрирование заголовка и reset справа. Все translated labels, действия и geometry interactions сохранены.
- Ревью и публикация: SOL source review PASS. MR [!4](https://gitlabs.itsrv.xyz/front/sinhrm.web/-/merge_requests/4) влит в `staging` (`75db9708287283ac1efd09aa51e3c59ec740ef92`); pipeline 276278 и независимая CI-проверка 41013607022 успешны.
- Проверки реализации: focused workspace spec 7/7; `npm run e2e:lint`, `npx ng lint`, `npm run test:docs` 9/9 и `git diff --check` успешны.
- Live staging: `/health` ответил 200; в новом окне проверены maximize/minimize/restore/close, 44px mobile targets, Dock и keyboard resize. Скриншоты: `D:/Projects/HRM/tmp/gitlab-browser-os/minimal-chrome/live/windows.png`, `focus.png`, `mobile.png`. На mobile отмечена горизонтальная прокрутка и у содержимого, и у Dock; отдельная задача исправляет Dock так, чтобы прокручивался только он.
- Закрытие: задача реализована, проверена, влита в staging и подтверждена на live staging. История evidence в `D:/Projects/HRM/tmp/gitlab-browser-os/minimal-chrome/release-proof.json` сохранена без изменений.
