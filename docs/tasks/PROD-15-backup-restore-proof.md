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
- Step: trace complete, implementation.
- Base: origin/main 3b4ac30.
- Decision: PostgreSQL service container provides pg_dump/pg_restore version 17; two fixed synthetic DB names; new temporary APP_KEY only in runner.
- Next: CI dump/restore evidence and Astra review.
- Blockers: PHP unavailable locally; production RPO/RTO/retention/access and attachment backup owner decisions pending.
- Timestamp: 2026-10-05 Asia/Saigon.
