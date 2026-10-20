#!/bin/sh
set -e

# node_modules lives in its own volume, which survives container rebuilds.
# Reinstall whenever package.json / package-lock.json differ from the last install.
HASH_FILE=node_modules/.deps-hash
CURRENT_HASH=$(cat package.json package-lock.json | sha256sum | cut -d' ' -f1)

if [ ! -f "$HASH_FILE" ] || [ "$(cat "$HASH_FILE")" != "$CURRENT_HASH" ]; then
  echo "[entrypoint] package files changed, running npm install..."
  npm install
  echo "$CURRENT_HASH" > "$HASH_FILE"
fi

exec "$@"
