# HRM-11 — браузерная рабочая среда

## Контекст из KB

По передаче от координатора: проверены GitLab metadata/CI и backend route документация; приняты как контекст для split-репозитория. В этом репозитории backend остаётся отдельным Laravel сервисом. API обращения идут через same-origin `/api`; CSRF cookie — `/api/sanctum/csrf-cookie`.

Дополнительно выполнен ограниченный поиск Vera по `sinhrm.itsrv.xyz GitLab staging frontend` (3 результата); устаревшие записи не использованы как доказательство текущего деплоя. Текущий маршрут публикации подтверждён напрямую GitLab project807/pipeline53 и действующим staging environment. Владелец явно разрешил публикацию на `sinhrm.itsrv.xyz`; целевой MR — `staging`, без запуска production `sinhrm.itstep.org`.

## План

1. Перенести только reviewed frontend delta из source `3301c3e96d7e2c9bcc1d807461b9df72808d35b9` относительно `899d4dab`, со снятием префикса `frontend/` согласно `D:/Projects/HRM/tmp/gitlab-browser-os/port-manifest.json`.
2. Сохранить целевые on-site server/start, Node engine и Vite override; адаптировать конфликт e2e flows к `/api/sanctum/csrf-cookie`.
3. Обновить модульную справку, worklog и этот state; выполнить быстрые целевые проверки без полного локального CI.

## Стан

- Время: 2026-10-30 Asia/Saigon.
- Worktree: `D:/Projects/HRM/worktrees/gitlab-browser-os`, branch `feat/browser-os-redesign`, base `66d440f5c82b0f719736a66491162290f444a9fb`.
- Source: `D:/Projects/HRM/worktrees/browser-os-integration`, reviewed SHA `3301c3e96d7e2c9bcc1d807461b9df72808d35b9`; reviewed source delta starts at `899d4dab`.
- Manifest scope: frontend-only. Target retains `server.mjs`, unix socket/`APP_SOCKET`, `run.sh`, Angular `dist/frontend` build path, Node >=24, and patched Vite override. No Vercel files added.
- Completed: applied the reviewed frontend delta and resolved three semantic conflicts (`package.json`, `package-lock.json`, `e2e/flows.pw.ts`); merged project coverage lint command, applied the reviewed `@angular/build` → Vite 8.3.5 override and source lock graph (retaining target package name and Node >=24 metadata), and rewrote CSRF path in browser flows to `/api/sanctum/csrf-cookie`. Target-only files were preserved. The original manifest reported 211 frontend paths because its path list used quote-escaped Git output and omitted eight modified Unicode-named employee-tab desktop snapshots; raw NUL-delimited Git diff confirms 219 changed paths total. Those eight snapshot files have now been copied byte-for-byte from source SHA `3301c3e9` and each target blob hash matches the reviewed source blob.
- Reviewed source evidence (from coordinator): independent SOL exact-tree review; full CI 400 browser / 1241 unit pass; actual visual review pass. This is source evidence, not target verification.
- Checks: `npm run e2e:lint` passed (inventory + project coverage, ESLint, TypeScript); `npm run test:docs` passed (9); `npx ng lint` passed; filtered Angular unit tests passed (27 across window navigation, desktop layout and theme); `npm run docs` passed (35 pages, no warnings); `git diff --check` passed after line-ending normalization. `npm ci` completed after the reviewed lock graph update; `npm audit --package-lock-only --audit-level=high` passed with zero high-severity findings and one remaining moderate `fast-uri` advisory. Confirmed installed Vite 8.3.5, Angular CLI/devkit 22.2.5, undici 8.12.3 and zod 4.6.6.
- Coordinator validation `41007706149`: full unit, docs, build, audit and all mobile browser checks passed; eight desktop employee-tab snapshot inventory checks failed because the original manifest omitted Unicode paths. The exact reviewed snapshots were restored.
- Coordinator validation `41008490907`: with snapshots fixed, only the `desktop-light` normal-motion performance scenario failed when the mascot `.hit` path intercepted a genuine click on the Reports Dock window.
- Fix: raised `.os-dock` to z-index 980, above mascot stage 950 and below topbar/modal backdrop 1000. The normal-motion performance scenario now asserts computed dock/mascot/topbar layer order while keeping actual Dock clicks, API checks and performance sampling intact.
- Targeted checks after fix: `npm run e2e:lint` passed; `npx ng test --watch=false --include src/app/features/shell/shell.layout.spec.ts` passed (32 tests); scoped `git diff --check` passed. No local browser suite/server run.
- UI flows in this worktree use recorded mocks. GitLab publication, deployment and live site verification remain coordinator-owned.
- Next: coordinator mirrors the frozen CSS/test/docs delta into validation worktree and reruns CI before review and GitLab release steps.
