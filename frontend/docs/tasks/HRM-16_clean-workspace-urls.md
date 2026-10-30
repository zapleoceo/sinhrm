# HRM-16 — чистые URL и восстановление рабочего пространства

## План

1. Сохранить текущую защищённую Router-модель с именованными окнами; проверить `browserUrl` и `NavigationExtras.state` на reload/popstate до расширения навигации.
2. Показывать в адресной строке канонический URL активного окна с его разрешёнными query-параметрами; внутреннее дерево окон продолжает проходить обычные guards.
3. Хранить ограниченный снимок состава и состояний окон в `sessionStorage`, раздельно по пользователю и вкладке; использовать историю Router для возврата к снимкам по Back/Forward. Чистая ссылка без снимка открывает штатный модуль в primary.
4. Сохранять геометрию окон в малом валидируемом per-user хранилище только после завершения жеста или клавиатурного изменения; не писать на каждом pointermove.
5. Проверить reload, Back/Forward, старые auxiliary URL, запрет неразрешённого модуля и ограничение хранимого UI query; обновить документацию.

## Стан

- Время: 2026-10-30 Asia/Saigon.
- Worktree: `D:/Projects/HRM/worktrees/gitlab-clean-urls`, branch `feat/clean-workspace-urls`, rebased onto `origin/staging` at `6225cf01a8afb80fd3ad24ae1bfce9f28e13ec07` (MR5 desktop/appearance release included). URL implementation commit `0fc2bef`; current pre-review branch HEAD `e3a4e16`. URL core paths and tests remain identical to the reviewed source commit; no desktop/appearance files are reverted.
- Контекст: текущие named outlets несут route/control state и проходят существующие auth/module guards. Angular Router предоставляет `browserUrl` для разделения отображаемого URL и внутреннего UrlTree, а `state` записывает данные в `history.state`; reload чистого canonical URL требует явного восстановления через Router. Root KB lookup: запрос `SinHRM Browser OS URL окна состояние маршрутизация` (`535415`) дал только общий контекст прошлого релиза; результат `534052` не относится к решению.
- Ограничения: без cookies, токенов, HR-записей, прямого обхода guards, сырой параллельной записи History API или изменений CSS. Старые OS URL остаются совместимыми.
- Выполнено: Router показывает канонический URL через `browserUrl`, сохраняя внутреннее дерево named outlets и bounded query; историю вкладки хранит ограниченный user-keyed снимок и состояние каждого history entry. Снимки проходят общую bounded-query/route/module validation до помещения в `history.state` и `sessionStorage`; credential-похожие параметры и query за лимитами остаются в обычной guarded-навигации без workspace payload. Reload восстанавливает через канонический match guard, popstate использует `NavigationStart.restoredState` и новый guarded `navigateByUrl`; приоритет primary (`osPrimary=1`) сохраняет primary URL. Старые auxiliary URL очищаются до канонического адреса, а скопированные RouterLink hrefs канонические. Геометрия хранится в ограниченном per-user localStorage и записывается после pointerup/keyboard/reset.
- Проверки после rebase на `6225cf0`: targeted Angular suite — 49/49 (auth guards + workspace navigation/snapshot + geometry/workspace); `npm run lint` — pass (все файлы); `npm run test:docs` — 9/9; `npm run e2e:lint` — pass; `git diff --check` — pass. Старый pre-MR5 source CI `41027278374` на mirror SHA `345d9561` завершился 7/7 jobs pass; desktop-light E2E — 128 passed, 8 skipped. Это подтверждает runtime URL E2E до/после browser-history fixture, но combined MR5+URL CI ещё требуется. Более ранний CI `41025168918` показал test-double и E2E setup ошибки; они исправлены в `a3db2fd`.
- E2E-контракт: реальные переходы идут с `/overview` в `/tasks` и `/people`; изменения управления, меняющие только состояние окна на текущем URL, не требуют отдельной записи истории. Для same-path query popstate тест захватывает две реальные snapshots из приложения и устанавливает их только в E2E fixture перед настоящими `page.goBack()`/`page.goForward()`; production History API остаётся нетронутым. Также сохраняются проверки reload roster/active/min/max/geometry, чистой ссылки во второй вкладке и role/module guards.
- Следующий шаг: SOL review exact combined MR5+URL diff; после authorized push root запустит full CI на combined tree, затем выпустит URL изменения последними.

## Контекст з KB

- Root выполнил KB lookup по запросу `SinHRM Browser OS URL окна состояние маршрутизация`: результат `535415` содержит общий контекст прошлого релиза, без прямого решения; `534052` не относится к задаче. Repo source и официальная документация Angular использованы как первичные источники.
