#!/usr/bin/env bash
# Runs on the EC2 server, as the `ubuntu` user, after the code has been updated.
# The deploy job in .github/workflows/ci.yml calls it through AWS Systems Manager;
# you can also run it by hand: `cd ~/SubtitleGenerator && git pull && bash Docker/deploy.sh`.
set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> Deploying $(git log -1 --format='%h %s')"

echo "==> Building and starting containers"
docker compose up -d --build

echo "==> Backend dependencies and migrations"
docker compose exec -T app composer install --no-interaction --no-progress --prefer-dist
docker compose exec -T app chown -R www-data:www-data storage bootstrap/cache
docker compose exec -T app php artisan migrate --force

echo "==> Reloading workers"
# Graceful: running jobs finish first, then Docker restarts Horizon (restart: unless-stopped) with the new code
docker compose exec -T horizon php artisan horizon:terminate
# nginx caches the app container's IP (known issue 8); frontend reinstalls npm deps on start when they changed
docker compose restart scheduler nginx frontend

echo "==> Health check"
for _ in $(seq 1 30); do
  if curl -fsS http://localhost:8000/api/test > /dev/null; then
    echo "API is up"
    docker image prune -f > /dev/null # drop the images replaced by this build, so the disk doesn't fill up
    exit 0
  fi
  sleep 2
done

echo "API did not answer on :8000 after 60 s" >&2
docker compose ps >&2
exit 1
