#!/usr/bin/env bash
# Fails the PR when a module's code changed but its documentation page did not.
# Module code: backend/app/Modules/<Name>/**, frontend/src/app/features/<name>/**, frontend/src/app/core/** (→ core)
# Required doc: docs/modules/<name>.md (lowercase).
set -euo pipefail
BASE="${1:-origin/main}"
changed=$(git diff --name-only "$BASE"...HEAD)
missing=()
modules=$( { echo "$changed" | sed -nE 's#^backend/app/Modules/([^/]+)/.*#\1#p'
             echo "$changed" | sed -nE 's#^frontend/src/app/features/([^/]+)/.*#\1#p'
             echo "$changed" | grep -qE '^frontend/src/app/core/' && echo core || true; } \
           | tr '[:upper:]' '[:lower:]' | sort -u)
for m in $modules; do
  doc="docs/modules/$m.md"
  if ! echo "$changed" | grep -qx "$doc"; then missing+=("$doc"); fi
done
if [ ${#missing[@]} -gt 0 ]; then
  echo "::error::Код модулей изменён, а документация нет. Обновите: ${missing[*]}"
  exit 1
fi
echo "docs-check: OK (${modules:-нет изменённых модулей})"
