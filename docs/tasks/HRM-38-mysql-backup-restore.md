# HRM-38 - MySQL 8.4 backup/restore proof

## Стан

- 2026-10-09 UTC. Branch `feat/mysql-backup-restore` is now maintained by the Claude coordinator session (handed over by Codex), worktree `D:/Projects/HRM/worktrees/claude-pr174`. Draft PR [#174](https://github.com/zapleoceo/sinhrm/pull/174) is synced with main by a merge commit (no force-push); conflicts were docs-only.
- Scope: CI-only synthetic MySQL 8.4 logical dump and isolated restore, exact table/count comparison, linked fixture, 2 MiB attachment, encrypted-vault check, and operator runbook. The backup workflow has only one MySQL job.
- Full CI on head `20488167` passed (12/12, including `synthetic-mysql-restore`, `tests` on MySQL 8.4, `lint` with the MySQL-only guard and `docs`). No production database, Vercel configuration, or other worktree was changed.
- Owner decision 2026-10-09: no transfer of live data; DevOps IT STEP start from the test dump attached to HRM-2. The former cutover runbook is removed by a separate cleanup of the former-database code, so this guide no longer links to it.
- Pending: independent review on the final head, merge, and owner RPO/RTO/retention decisions for the real IT STEP database. The synthetic fixture does not establish production recovery.
