# HRM-16 — чистые URL и восстановление рабочего пространства

## План

1. Сохранить текущую защищённую Router-модель с именованными окнами; проверить `browserUrl` и `NavigationExtras.state` на reload/popstate до расширения навигации.
2. Показывать в адресной строке канонический URL активного окна с его разрешёнными query-параметрами; внутреннее дерево окон продолжает проходить обычные guards.
3. Хранить ограниченный снимок состава и состояний окон в `sessionStorage`, раздельно по пользователю и вкладке; использовать историю Router для возврата к снимкам по Back/Forward. Чистая ссылка без снимка открывает штатный модуль в primary.
4. Сохранять геометрию окон в малом валидируемом per-user хранилище только после завершения жеста или клавиатурного изменения; не писать на каждом pointermove.
5. Проверить reload, Back/Forward, старые auxiliary URL, запрет неразрешённого модуля и ограничение хранимого UI query; обновить документацию.

## Стан

- Время: 2026-10-30 Asia/Saigon.
- Worktree: `D:/Projects/HRM/worktrees/gitlab-clean-urls`, branch `feat/clean-workspace-urls`, rebased onto `origin/staging` at `75db9708287283ac1efd09aa51e3c59ec740ef92`; feature commit `2fd3a87258fd565827f8336e7b60692484dcc0f6` (source `8d3c826` implementation paths match exactly after rebase).
- Контекст: текущие named outlets несут route/control state и проходят существующие auth/module guards. Angular Router предоставляет `browserUrl` для разделения отображаемого URL и внутреннего UrlTree, а `state` записывает данные в `history.state`; reload чистого canonical URL требует явного восстановления через Router. Root KB lookup: запрос `SinHRM Browser OS URL окна состояние маршрутизация` (`535415`) дал только общий контекст прошлого релиза; результат `534052` не относится к решению.
- Ограничения: без cookies, токенов, HR-записей, прямого обхода guards, сырой параллельной записи History API или изменений CSS. Старые OS URL остаются совместимыми.
- Выполнено: Router показывает канонический URL через `browserUrl`, сохраняя внутреннее дерево named outlets и bounded query; историю вкладки хранит ограниченный user-keyed снимок и состояние каждого history entry. Снимки проходят общую bounded-query/route/module validation до помещения в `history.state` и `sessionStorage`; credential-похожие параметры и query за лимитами остаются в обычной guarded-навигации без workspace payload. Reload восстанавливает через канонический match guard, popstate использует `NavigationStart.restoredState` и новый guarded `navigateByUrl`; приоритет primary (`osPrimary=1`) сохраняет primary URL. Старые auxiliary URL очищаются до канонического адреса, а скопированные RouterLink hrefs канонические. Геометрия хранится в ограниченном per-user localStorage и записывается после pointerup/keyboard/reset.
- Проверки после rebase: targeted Angular specs — 36/36 (включая primary-фильтр при восстановлении открытого окна, отказ credential-параметру, отсутствие history/session снимка для credential и oversized query, а также отмену отложенного восстановления при смене пользователя); `npm run lint` — pass (все файлы); `npm run test:docs` — 9/9; `npm run e2e:lint` — pass; `git diff --check` — pass. Browser E2E и полный build/CI ещё не запускались (root выполняет release CI).
- E2E обновлены: URL assertions в `e2e/os-windows.pw.ts` и двух изолированных блоках `e2e/flows.pw.ts` проверяют canonical path и состояние окон в DOM; проверяются Back/Forward при одинаковом canonical path и разных query/roster, same-tab reload восстанавливает roster, active, minimized, maximized и geometry, а открытие clean URL во второй вкладке не наследует workspace. Playwright lint/typecheck прошли; запуск браузерных сценариев ожидает CI.
- Следующий шаг: SOL final source review, затем root запустит browser E2E/full CI и выпустит URL-изменения независимо от desktop MR5.

## Контекст з KB

- Root выполнил KB lookup по запросу `SinHRM Browser OS URL окна состояние маршрутизация`: результат `535415` содержит общий контекст прошлого релиза, без прямого решения; `534052` не относится к задаче. Repo source и официальная документация Angular использованы как первичные источники.
