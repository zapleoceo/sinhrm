#!/bin/sh
# Post-deploy hook. The frontend has no database or migrations, so there is nothing to apply: exit 0 right away.
# Never start the server here — starting the app is the orchestrator's separate `start` step (`node server.mjs`).
set -eu
echo "[run.sh] frontend: nothing to migrate."
