# HRM-38 — MySQL 8.4 backup/restore proof

## Стан

- 2026-10-08 UTC. Task HRM-38 is In progress, assigned to the authenticated Jira owner account and executed by Codex. Branch `feat/mysql-backup-restore`, isolated worktree `D:/Projects/sinhrm-wt/mysql-backup`, based on Claude's `feat/mysql-portability` commit `a6db45e3bf9c1d63cca79653826b8bfde020f2c3` (Draft PR #170). Separate Draft PR for this task is pending.
- Scope: CI-only synthetic MySQL 8.4 logical dump and isolated restore, exact table/count comparison, linked fixture/2 MiB attachment/encrypted-vault checks and DevOps procedure. The workflow contains only the MySQL backup/restore proof.
- Evidence: local Node orchestration checks passed; PHP, MySQL and Bash integration checks require the repository CI runner. No source or production DB, Vercel configuration, credentials or Claude worktree changed.
- Pending: CI job and full PR checks on the final SHA, independent review, target IT STEP backup/restore drill and owner RPO/RTO/retention decisions.
- Next: run CI on a Draft PR, resolve any concrete failures, and record exact run links here or in PR/Jira without changing live infrastructure.
