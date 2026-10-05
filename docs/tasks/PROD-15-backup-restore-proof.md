# PROD-15 — Synthetic database backup/restore proof

## План
1. Separate Postgres 17 CI service; actual Laravel migrations, synthetic related recruiting rows and encrypted vault.
2. Dump with matching client, restore separate empty DB, assert relationships and same-key decryption; never upload dump.
3. Safe operator runbook covering DB/key/object storage, isolated restore, stop/rollback, owner-pending RPO/RTO/retention/access.
4. Targeted script checks, full CI, draft PR; no production actions.

## Контекст з KB
Vera query SinHRM backup restore recovery Postgres APP_KEY returned three unrelated Stepan2 events, no applicable project evidence.
Prior Rovo/Drive SinHRM lookup had no results (orchestrator); cloudId unavailable. Current migrations, SecretVault and CI are primary sources.

## Стан
- Step: implementation complete, draft PR #150 and CI verification.
- Base: origin/main 3b4ac30.
- Decision: PostgreSQL service container provides pg_dump/pg_restore version 17; two fixed synthetic DB names; new temporary APP_KEY only in runner.
- Next: CI dump/restore evidence and Astra review.
- Blockers: PHP unavailable locally; production RPO/RTO/retention/access and attachment backup owner decisions pending.
- Timestamp: 2026-10-05 Asia/Saigon.

## Проверки
Local Node checks: 2 passed; bash negative-command gate skipped on Windows and runs in Linux CI.
Bash syntax, git whitespace and docs/tests guards passed. PHP absent locally; actual dump/restore requires CI.
Initial Actions workflow validation failed: job.services context was declared at job env level.
Moved container ID to step env, where job context is available; no permission/guard weakening.
Next: green restore job/full CI and Astra review. No production actions or deployment.

## CI evidence — 2026-10-05
Restore run 37274448033 SUCCESS on code head a828020ec9ca9d53339e0281cc8fc754a5f0c0d0:
PostgreSQL client 17.11; Linux safety tests 3/3; seed 20 assertions; restored verification 22 assertions.
Each phase deliberately skips the opposite phase. Synthetic migrate/dump/restore/verify elapsed 4s, not production RTO.
Full CI 37274448008: lint, docs, worklog, api-docs, extension, security green; frontend/tests/ui-parity pending at handoff.
Next: root monitors final CI and Astra review; production backup readiness remains OWNER PENDING.
