---
date: 2026-10-08
area: Расширение
---
TypeScript 7 в `extension/` не принят: `typescript-eslint` 8.71.1 допускает только `typescript <6.1.0`, `npm ci` падает с ERESOLVE. Dependabot для `/extension` игнорирует мажор TypeScript; решение записано в [development.md](guides/development.md).
