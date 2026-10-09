# MySQL 8.4 backup and isolated restore (HRM-38)

## CI proof

The `synthetic-mysql-restore` job in `.github/workflows/backup-restore.yml` runs against a disposable MySQL 8.4 service. It migrates an empty source, creates a fresh CI `APP_KEY`, and seeds linked recruiting and employee rows, a 2 MiB database attachment, and an encrypted `integration_secrets` value. The matching service client runs `mysqldump` with `--single-transaction --routines --triggers --events --hex-blob --no-tablespaces --set-gtid-purged=OFF`. The job records exact per-table row counts, creates a separate restore database, removes the synthetic source, restores the dump, compares table/count inventory, and checks relationships, foreign-key cascade, next `AUTO_INCREMENT`, attachment bytes/SHA-256 and decryption under the unchanged key. A shell trap removes the dump and inventory files; the workflow does not upload them.

This proves a synthetic logical dump and restore. It does not prove a production backup, PITR or RPO/RTO. The CI script accepts only fixed local CI databases and must never be run against a live database.

## Owner decisions before a production drill

| Decision | Status |
|---|---|
| RPO, backup/PITR frequency and RTO including application recovery | **OWNER PENDING** |
| Retention, encryption, separate storage and recovery of key versions | **OWNER PENDING** |
| Restore operator, backup access, MFA and break-glass procedure | **OWNER PENDING** |
| Restore drill frequency and alerting for missed or failed backups | **OWNER PENDING** |
| Write freeze, cutover authority and rollback criteria | **OWNER PENDING** |

DevOps must confirm MySQL 8.4, InnoDB-only tables or a write/DDL freeze for a consistent snapshot, backup privileges including routines and triggers, TLS certificate validation, encrypted storage and retention, and an independent restore destination. Preserve the corresponding `APP_KEY` and any earlier key versions through a separate protected channel. The database stores document and career-submission content; inventory all other storage adapters and externally hosted objects separately. A database row containing an external link does not prove that its object can be restored.

## Isolated operator drill

Obtain a protected MySQL client option file from the approved secret manager (mode `0600`). Use separate source and restore accounts. Independently confirm source and target host and database names. Use a matching MySQL 8.4 client on a trusted host with the certificate validation required by IT STEP. Freeze writes and DDL if the consistency preconditions above are not met. Never log passwords or include them in command arguments.

```bash
# Paths, endpoints and database names come from the approved runbook.
umask 077
mysqldump --defaults-extra-file="$SOURCE_CLIENT_CNF" --single-transaction --routines --triggers --events \
  --hex-blob --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 \
  --result-file="$BACKUP_FILE" "$SOURCE_DATABASE"
sha256sum "$BACKUP_FILE" > "$BACKUP_SHA_FILE"
sha256sum --check "$BACKUP_SHA_FILE"
# Provision a NEW empty isolated restore database through the approved operator workflow first.
mysql --defaults-extra-file="$RESTORE_CLIENT_CNF" --database="$RESTORE_DATABASE" < "$BACKUP_FILE"
```

`--defaults-extra-file` must be the first client option. Never use `--add-drop-database`, `--all-databases`, `--force`, or a restore target already in use. Disable jobs, mail, webhooks and other external effects in the isolated restore application. Verify exact table/row counts, primary and foreign keys, next auto-increments, attachment bytes and SHA-256, and encrypted-vault decryption with the retained `APP_KEY`. Record the backup hash, restore point, MySQL and application versions, elapsed time, results and exceptions without personal data or secrets. Compare with approved RPO/RTO.

Stop before switching traffic if the hash, key, objects, schema, relationships or target identity do not match. Keep the source untouched. Switching production to a restored database is a separate DevOps step with its own rollback criteria. Remove disposable drill resources after recording evidence, subject to the approved retention policy.
