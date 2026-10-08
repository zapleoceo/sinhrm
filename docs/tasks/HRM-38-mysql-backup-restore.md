# HRM-38 - MySQL 8.4 backup/restore proof

## State

- 2026-10-08 UTC. In progress on Codex-owned branch `feat/mysql-backup-restore`, isolated worktree `D:/Projects/sinhrm-wt/mysql-backup`. Draft PR [#174](https://github.com/zapleoceo/sinhrm/pull/174) is rebased on MySQL-only main (ADR 0011).
- Scope: CI-only synthetic MySQL 8.4 logical dump and isolated restore, exact table/count comparison, linked fixture, 2 MiB attachment, encrypted-vault check, and operator runbook. The backup workflow has only one MySQL job.
- Earlier pre-rebase CI [full](https://github.com/zapleoceo/sinhrm/actions/runs/37736231145) and [backup proof](https://github.com/zapleoceo/sinhrm/actions/runs/37736231034) passed. These runs do not validate the rebased SHA.
- Current local Node orchestration and MySQL-only guard checks pass. PHP/MySQL/Bash integration checks require repository CI. No production database, Vercel configuration, or other worktree was changed.
- Pending: final PR CI and independent review, an authorized isolated production-data restore drill, and owner RPO/RTO/retention decisions. The synthetic fixture does not establish complete production recovery.
