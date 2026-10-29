# Foundation — реальные CI доказательства

CI40921259802, sourcefe226e002a06d80b3175ddaa1e9f2b9b8e4de704: frontend полный unit/lint/build PASS, backend/extension/docs/worklog PASS; security отклонён существующими dependency advisories (отдельныйPR248). Build CSS budget после shared chrome/motion refactor проходит.

Browser UI report:484 tests,362 expected,9 unexpected,113 skipped. Восемь ожидаемых inventory failures относятся только к новым appearance snapshots и добавленному пункту user menu, во всех4 theme/viewport проектах. Девятый — дефект тестового чтения unsupported empty WebKit CSS property как активного filter; тест исправлен без изменения проверок реальных standard/nonempty prefixed filters. Это не девять подтверждённых поломок приложения.

Root извлёк actual inventory attachments и фактически открыл CI PNG appearance desktop-light/mobile-dark и vacancy-board desktop-light/users mobile-dark. Light/dark inventories идентичны. Для существующего user-menu desktop/mobile подтверждено строго одно добавление menuitem «Зовнішній вигляд», никаких удалений/переименований/других изменений. Новые appearance desktop/mobile inventories приняты из actual UI, не сгенерированы из реализации. Axe baseline не изменён.

Один desktop-light screenshot показывает пустые selected labels схемы/обоев, хотя другие3 проекта их показывают. Product код на основании единичного кадра не менялся; перед capture и в flow добавлены строгие assertions реального select value text «Власна»/«Туман». Следующий CI должен доказать готовность и завершить axe/layout checks новой страницы, которые раньше останавливались на missing baseline.

Артефакты: D:/Projects/HRM/tmp/browser-os-system/ci-40921259802/{report,screens,parsed-report,actual-selected-inventories.json}. Это compiled приложение с существующими synthetic CI API fixtures, не production/provider live acceptance. Настоящие окна и desktop находятся в отдельных ветках; весь OS redesign пока не завершён. Новые source/test/baseline corrections требуют нового CI и SOL review exact head.
