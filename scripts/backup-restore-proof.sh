#!/usr/bin/env bash
set -euo pipefail

# CI-only, fixed disposable databases. No production connection strings or secrets are accepted.
[[ "${GITHUB_ACTIONS:-}" == true ]] || { echo 'CI-only restore proof'; exit 1; }
[[ "${DB_HOST:-}" == 127.0.0.1 && "${DB_DATABASE:-}" == app_dump_source && "${APP_ENV:-}" == testing ]] || exit 1
[[ -z "${DB_URL:-}" ]] || exit 1
[[ "${POSTGRES_CONTAINER:-}" =~ ^[a-f0-9]+$ ]] || exit 1

proof_dump="$PWD/.synthetic-restore-proof.dump"
trap 'rm -f -- "$proof_dump"' EXIT
proof_started=$SECONDS

cd backend
php artisan migrate --force --no-interaction
RESTORE_PROOF_PHASE=seed php artisan test --filter BackupRestoreProofTest
cd ..

# Both client binaries come from the same postgres:17 service as the server.
docker exec "$POSTGRES_CONTAINER" pg_dump --version
docker exec "$POSTGRES_CONTAINER" pg_dump -U app -d app_dump_source --format=custom --no-owner --no-acl > "$proof_dump"
test -s "$proof_dump"
docker exec "$POSTGRES_CONTAINER" createdb -U app app_dump_restored
# Source is removed: verification cannot accidentally pass against the original DB.
docker exec "$POSTGRES_CONTAINER" dropdb -U app app_dump_source
docker exec -i "$POSTGRES_CONTAINER" pg_restore -U app -d app_dump_restored --exit-on-error --single-transaction --no-owner --no-acl < "$proof_dump"

cd backend
DB_DATABASE=app_dump_restored RESTORE_PROOF_PHASE=verify php artisan test --filter BackupRestoreProofTest
cd ..
proof_elapsed=$((SECONDS - proof_started))
echo "Synthetic DB dump/restore proof passed in ${proof_elapsed}s (not production RTO)."
if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
  printf 'Synthetic PostgreSQL 17 migration/dump/restore/verification: PASS (%ss).\n\nNot production RTO. Object storage and external files are outside this DB proof. No dump artifact uploaded.\n' "$proof_elapsed" >> "$GITHUB_STEP_SUMMARY"
fi
