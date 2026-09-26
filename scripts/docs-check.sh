#!/usr/bin/env bash
# Fails the PR when a module's code changed but its documentation page did not.
# Module code: backend/app/Modules/<Name>/**, frontend/src/app/features/<name>/**, frontend/src/app/core/** (→ core),
#              extension/** (the browser extension, top-level folder → extension)
# Required doc: docs/modules/<name>.md in kebab-case (GoogleWorkspace → google-workspace, SafeSpeak → safe-speak, features/mail-agent → mail-agent).
#               Aliases: TimeOff (backend) and features/timeoff → timeoff.md.
set -euo pipefail
BASE="${1:-origin/main}"
changed=$(git diff --name-only "$BASE"...HEAD)
missing=()
modules=$( { echo "$changed" | sed -nE 's#^backend/app/Modules/([^/]+)/.*#\1#p'
             echo "$changed" | sed -nE 's#^frontend/src/app/features/([^/]+)/.*#\1#p'
             echo "$changed" | grep -qE '^frontend/src/app/core/' && echo core || true
             echo "$changed" | grep -qE '^extension/' && echo extension || true; } \
           | sed -E 's/([a-z0-9])([A-Z])/\1-\2/g' | tr '[:upper:]' '[:lower:]' | sed -E 's/^time-off$/timeoff/' | sort -u)
for m in $modules; do
  doc="docs/modules/$m.md"
  if ! echo "$changed" | grep -qx "$doc"; then missing+=("$doc"); fi
done
if [ ${#missing[@]} -gt 0 ]; then
  echo "::error::Код модулей изменён, а документация нет. Обновите: ${missing[*]}"
  exit 1
fi
echo "docs-check: OK (${modules:-нет изменённых модулей})"
