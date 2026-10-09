#!/usr/bin/env bash
set -euo pipefail

# CI-only proof against fixed, disposable MySQL 8.4 service databases.
[[ "${GITHUB_ACTIONS:-}" == true && "${APP_ENV:-}" == testing ]] || { echo 'Synthetic CI restore only'; exit 1; }
[[ "${DB_CONNECTION:-}" == mysql && "${DB_HOST:-}" == 127.0.0.1 && "${DB_PORT:-}" == 3306 ]] || exit 1
[[ "${DB_DATABASE:-}" == app_mysql_dump_source && "${DB_USERNAME:-}" == app && "${DB_PASSWORD:-}" == app ]] || exit 1
[[ -z "${DB_URL:-}" && -z "${DATABASE_URL:-}" && -z "${MYSQL_ATTR_SSL_CA:-}" ]] || exit 1
[[ "${MYSQL_CONTAINER:-}" =~ ^[a-f0-9]+$ ]] || exit 1

proof_dump="$PWD/.synthetic-mysql-restore-proof.sql"
proof_before="$PWD/.synthetic-mysql-restore-before.tsv"
proof_after="$PWD/.synthetic-mysql-restore-after.tsv"
trap 'rm -f -- "$proof_dump" "$proof_before" "$proof_after"' EXIT
proof_started=$SECONDS

table_counts() {
  local database="$1" table count
  docker exec -e MYSQL_PWD=app "$MYSQL_CONTAINER" mysql -u app -N -B -e \
    "SELECT table_name FROM information_schema.tables WHERE table_schema = '$database' AND table_type = 'BASE TABLE' ORDER BY table_name" |
    while IFS= read -r table; do
      [[ "$table" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || exit 1
      count=$(docker exec -e MYSQL_PWD=app "$MYSQL_CONTAINER" mysql -u app -N -B "$database" -e "SELECT COUNT(*) FROM \`$table\`")
      printf '%s\t%s\n' "$table" "$count"
    done
}

cd backend
php artisan migrate --force --no-interaction
MYSQL_RESTORE_PROOF_PHASE=seed php artisan test --filter MySqlBackupRestoreProofTest
cd ..
table_counts app_mysql_dump_source > "$proof_before"
test -s "$proof_before"

# Both clients are from the same disposable mysql:8.4 service as the server.
docker exec "$MYSQL_CONTAINER" mysqldump --version
docker exec -e MYSQL_PWD=app "$MYSQL_CONTAINER" mysqldump -u app --single-transaction --routines --triggers --events --hex-blob \
  --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 app_mysql_dump_source > "$proof_dump"
test -s "$proof_dump"
docker exec -e MYSQL_PWD=root "$MYSQL_CONTAINER" mysql -u root -e \
  "CREATE DATABASE app_mysql_dump_restored CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci; GRANT ALL ON app_mysql_dump_restored.* TO 'app'@'%';"
# Remove the source before verification so tests cannot accidentally read it.
docker exec -e MYSQL_PWD=root "$MYSQL_CONTAINER" mysql -u root -e 'DROP DATABASE app_mysql_dump_source'
docker exec -i -e MYSQL_PWD=app "$MYSQL_CONTAINER" mysql -u app --default-character-set=utf8mb4 app_mysql_dump_restored < "$proof_dump"
table_counts app_mysql_dump_restored > "$proof_after"
diff -u "$proof_before" "$proof_after"

cd backend
DB_DATABASE=app_mysql_dump_restored MYSQL_RESTORE_PROOF_PHASE=verify php artisan test --filter MySqlBackupRestoreProofTest
cd ..
proof_elapsed=$((SECONDS - proof_started))
echo "Synthetic MySQL dump/restore proof passed in ${proof_elapsed}s (not production RTO)."
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  printf 'Synthetic MySQL 8.4 migration/dump/isolated restore/verification: PASS (%ss).\n\nNot production RTO. No dump artifact uploaded.\n' "$proof_elapsed" >> "$GITHUB_STEP_SUMMARY"
fi
